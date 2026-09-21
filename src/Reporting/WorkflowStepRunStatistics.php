<?php

declare(strict_types=1);

namespace Fluxx\Reporting;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Fluxx\Entity\Enum\WorkflowStepDeduplicationStatus;
use Fluxx\Entity\Enum\WorkflowStepRunStatus;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;

/**
 * Provides step-run statistics and reporting read models outside of the
 * WorkflowStepRunRepository so the repository stays focused on entity lookups.
 *
 * All methods run read-only SQL against the Fluxx step-run table and return
 * denormalized reporting structures (aggregates or per-run/per-step rows);
 * they never hydrate WorkflowStepRun entities.
 */
final readonly class WorkflowStepRunStatistics
{
    public function __construct(private ManagerRegistry $registry)
    {
    }

    /**
     * @param list<WorkflowRun> $workflowRuns
     * @param list<string> $stepNames
     * @return array<string, array{
     *     stepType: string,
     *     status: string,
     *     processedCount: int,
     *     successCount: int,
     *     errorCount: int,
     *     durationMs: ?int,
     *     memoryPeakBytes: ?int,
     *     idempotenceKey: ?string,
     *     deduplicationStatus: string,
     *     deduplicatedFromRunId: ?string,
     *     errorMessage: ?string,
     *     errorPayload: ?array<string, mixed>
     * }>
     */
    public function findLatestByWorkflowRunsAndStepNamesIndexed(array $workflowRuns, array $stepNames): array
    {
        [$workflowRunIds, $runIdsByDatabaseId] = $this->extractWorkflowRunIdentifiers($workflowRuns);
        $stepNames = array_values(array_unique($stepNames));

        if ($workflowRunIds === [] || $stepNames === []) {
            return [];
        }

        $rows = $this->connection()->fetchAllAssociative(
            <<<'SQL'
                SELECT
                    workflow_step_run.workflow_run_id,
                    workflow_step_run.step_name,
                    workflow_step_run.step_type,
                    workflow_step_run.status,
                    workflow_step_run.processed_count,
                    workflow_step_run.success_count,
                    workflow_step_run.error_count,
                    workflow_step_run.duration_ms,
                    workflow_step_run.memory_peak_bytes,
                    workflow_step_run.idempotence_key,
                    workflow_step_run.deduplication_status,
                    workflow_step_run.error_message,
                    workflow_step_run.metadata,
                    deduplicated_from_workflow_run.run_id AS deduplicated_from_run_id
                FROM fluxx_workflow_step_run workflow_step_run
                INNER JOIN (
                    SELECT
                        workflow_run_id,
                        step_name,
                        MAX(id) AS latest_id
                    FROM fluxx_workflow_step_run
                    WHERE workflow_run_id IN (:workflowRunIds)
                      AND step_name IN (:stepNames)
                    GROUP BY workflow_run_id, step_name
                ) latest_step_run ON latest_step_run.latest_id = workflow_step_run.id
                LEFT JOIN fluxx_workflow_step_run deduplicated_from_step_run
                    ON deduplicated_from_step_run.id = workflow_step_run.deduplicated_from_step_run_id
                LEFT JOIN fluxx_workflow_run deduplicated_from_workflow_run
                    ON deduplicated_from_workflow_run.id = deduplicated_from_step_run.workflow_run_id
            SQL,
            [
                'workflowRunIds' => $workflowRunIds,
                'stepNames' => $stepNames,
            ],
            [
                'workflowRunIds' => ArrayParameterType::INTEGER,
                'stepNames' => ArrayParameterType::STRING,
            ],
        );

        $indexed = [];

        foreach ($rows as $row) {
            $workflowRunId = isset($row['workflow_run_id']) ? (int) $row['workflow_run_id'] : null;
            $runId = $workflowRunId !== null ? ($runIdsByDatabaseId[$workflowRunId] ?? null) : null;
            $stepName = isset($row['step_name']) ? (string) $row['step_name'] : '';

            if ($runId === null || $stepName === '') {
                continue;
            }

            $metadata = json_decode((string) ($row['metadata'] ?? '[]'), true);
            $errorPayload = is_array($metadata) && is_array($metadata['error'] ?? null)
                ? $metadata['error']
                : null;

            $indexed[$runId . '::' . $stepName] = [
                'stepType' => (string) ($row['step_type'] ?? 'custom'),
                'status' => (string) ($row['status'] ?? WorkflowStepRunStatus::Pending->value),
                'processedCount' => (int) ($row['processed_count'] ?? 0),
                'successCount' => (int) ($row['success_count'] ?? 0),
                'errorCount' => (int) ($row['error_count'] ?? 0),
                'durationMs' => isset($row['duration_ms']) ? (int) $row['duration_ms'] : null,
                'memoryPeakBytes' => isset($row['memory_peak_bytes']) ? (int) $row['memory_peak_bytes'] : null,
                'idempotenceKey' => isset($row['idempotence_key']) ? (string) $row['idempotence_key'] : null,
                'deduplicationStatus' => (string) ($row['deduplication_status'] ?? WorkflowStepDeduplicationStatus::None->value),
                'deduplicatedFromRunId' => isset($row['deduplicated_from_run_id']) ? (string) $row['deduplicated_from_run_id'] : null,
                'errorMessage' => isset($row['error_message']) ? (string) $row['error_message'] : null,
                'errorPayload' => $errorPayload,
            ];
        }

        return $indexed;
    }

    /**
     * @param list<WorkflowRun> $workflowRuns
     * @return array{
     *     processedTotal: int,
     *     successTotal: int,
     *     errorTotal: int,
     *     durationTotal: int,
     *     durationCount: int,
     *     maxDurationMs: ?int
     * }
     */
    public function summarizeByWorkflowRuns(array $workflowRuns): array
    {
        [$workflowRunIds] = $this->extractWorkflowRunIdentifiers($workflowRuns);

        if ($workflowRunIds === []) {
            return [
                'processedTotal' => 0,
                'successTotal' => 0,
                'errorTotal' => 0,
                'durationTotal' => 0,
                'durationCount' => 0,
                'maxDurationMs' => null,
            ];
        }

        $summary = $this->connection()->fetchAssociative(
            'SELECT
                SUM(workflow_step_run.processed_count) AS processed_total,
                SUM(workflow_step_run.success_count) AS success_total,
                SUM(workflow_step_run.error_count) AS error_total,
                SUM(CASE WHEN workflow_step_run.duration_ms IS NOT NULL THEN workflow_step_run.duration_ms ELSE 0 END) AS duration_total,
                SUM(CASE WHEN workflow_step_run.duration_ms IS NOT NULL THEN 1 ELSE 0 END) AS duration_count,
                MAX(workflow_step_run.duration_ms) AS max_duration_ms
             FROM fluxx_workflow_step_run workflow_step_run
             WHERE workflow_step_run.workflow_run_id IN (:workflowRunIds)',
            [
                'workflowRunIds' => $workflowRunIds,
            ],
            [
                'workflowRunIds' => ArrayParameterType::INTEGER,
            ],
        ) ?: [];

        return [
            'processedTotal' => (int) ($summary['processed_total'] ?? 0),
            'successTotal' => (int) ($summary['success_total'] ?? 0),
            'errorTotal' => (int) ($summary['error_total'] ?? 0),
            'durationTotal' => (int) ($summary['duration_total'] ?? 0),
            'durationCount' => (int) ($summary['duration_count'] ?? 0),
            'maxDurationMs' => isset($summary['max_duration_ms']) ? (int) $summary['max_duration_ms'] : null,
        ];
    }

    /**
     * @param list<WorkflowRun> $workflowRuns
     * @return array<string, list<array{
     *     code: string,
     *     type: string,
     *     status: string,
     *     processed: int,
     *     success: int,
     *     errors: int,
     *     durationMs: ?int,
     *     memoryPeakBytes: ?int,
     *     retries: int,
     *     startedAt: ?DateTimeImmutable,
     *     finishedAt: ?DateTimeImmutable,
     *     error: ?string,
     *     errorDetails: list<string>
     * }>>
     */
    public function findErroredStepRowsByWorkflowRunsGrouped(array $workflowRuns): array
    {
        [$workflowRunIds, $runIdsByDatabaseId] = $this->extractWorkflowRunIdentifiers($workflowRuns);

        if ($workflowRunIds === []) {
            return [];
        }

        $rows = $this->connection()->fetchAllAssociative(
            'SELECT
                workflow_step_run.workflow_run_id,
                workflow_step_run.step_name,
                workflow_step_run.step_type,
                workflow_step_run.status,
                workflow_step_run.processed_count,
                workflow_step_run.success_count,
                workflow_step_run.error_count,
                workflow_step_run.duration_ms,
                workflow_step_run.memory_peak_bytes,
                workflow_step_run.retry_count,
                workflow_step_run.started_at,
                workflow_step_run.finished_at,
                workflow_step_run.error_message,
                workflow_step_run.metadata
             FROM fluxx_workflow_step_run workflow_step_run
             WHERE workflow_step_run.workflow_run_id IN (:workflowRunIds)
               AND workflow_step_run.status IN (:statuses)
             ORDER BY workflow_step_run.workflow_run_id ASC, workflow_step_run.id ASC',
            [
                'workflowRunIds' => $workflowRunIds,
                'statuses' => [
                    WorkflowStepRunStatus::Failed->value,
                    WorkflowStepRunStatus::Retrying->value,
                    WorkflowStepRunStatus::Cancelled->value,
                ],
            ],
            [
                'workflowRunIds' => ArrayParameterType::INTEGER,
                'statuses' => ArrayParameterType::STRING,
            ],
        );

        $grouped = [];

        foreach ($rows as $row) {
            $workflowRunId = isset($row['workflow_run_id']) ? (int) $row['workflow_run_id'] : null;
            $runId = $workflowRunId !== null ? ($runIdsByDatabaseId[$workflowRunId] ?? null) : null;
            $stepCode = (string) ($row['step_name'] ?? '');

            if ($runId === null || $stepCode === '') {
                continue;
            }

            $metadata = json_decode((string) ($row['metadata'] ?? '[]'), true);
            $errorPayload = is_array($metadata) && is_array($metadata['error'] ?? null)
                ? $metadata['error']
                : null;
            $errorDetails = [];

            if ($errorPayload !== null) {
                foreach ($errorPayload as $key => $value) {
                    if (is_scalar($value) || $value === null) {
                        $errorDetails[] = sprintf('%s: %s', $key, $value ?? 'null');
                    }
                }
            }

            $grouped[$runId][] = [
                'code' => $stepCode,
                'type' => (string) ($row['step_type'] ?? 'custom'),
                'status' => (string) ($row['status'] ?? WorkflowStepRunStatus::Pending->value),
                'processed' => (int) ($row['processed_count'] ?? 0),
                'success' => (int) ($row['success_count'] ?? 0),
                'errors' => (int) ($row['error_count'] ?? 0),
                'durationMs' => isset($row['duration_ms']) ? (int) $row['duration_ms'] : null,
                'memoryPeakBytes' => isset($row['memory_peak_bytes']) ? (int) $row['memory_peak_bytes'] : null,
                'retries' => (int) ($row['retry_count'] ?? 0),
                'startedAt' => $this->toDateTimeImmutable($row['started_at'] ?? null),
                'finishedAt' => $this->toDateTimeImmutable($row['finished_at'] ?? null),
                'error' => isset($row['error_message']) ? (string) $row['error_message'] : null,
                'errorDetails' => $errorDetails,
            ];
        }

        return $grouped;
    }

    /**
     * @return array{
     *     retryRunCount: int,
     *     processedTotal: int,
     *     successTotal: int,
     *     recordErrorTotal: int,
     *     steps: array<string, array{
     *         durationTotal: int,
     *         durationCount: int,
     *         failureCount: int,
     *         retryCount: int,
     *         idempotenceHitCount: int,
     *         executionCount: int
     *     }>
     * }
     */
    public function aggregateLatestStepStatisticsByWorkflowNameSince(string $workflowName, DateTimeImmutable $startAt): array
    {
        $connection = $this->connection();
        $latestStepRunsSubquery = $this->latestStepRunsByWorkflowSinceSubquery();
        $baseParams = [
            'workflowName' => $workflowName,
            'startAt' => $startAt,
        ];
        $baseTypes = [
            'startAt' => Types::DATETIME_IMMUTABLE,
        ];

        $rows = $connection->fetchAllAssociative(
            sprintf(
                'SELECT
                    workflow_step_run.step_name,
                    SUM(CASE WHEN workflow_step_run.duration_ms IS NOT NULL THEN workflow_step_run.duration_ms ELSE 0 END) AS duration_total,
                    SUM(CASE WHEN workflow_step_run.duration_ms IS NOT NULL THEN 1 ELSE 0 END) AS duration_count,
                    SUM(CASE WHEN workflow_step_run.status = :failedStatus THEN 1 ELSE 0 END) AS failure_count,
                    SUM(workflow_step_run.retry_count) AS retry_count,
                    SUM(CASE WHEN workflow_step_run.deduplication_status <> :noDeduplicationStatus THEN 1 ELSE 0 END) AS idempotence_hit_count,
                    COUNT(*) AS execution_count,
                    SUM(workflow_step_run.processed_count) AS processed_total,
                    SUM(workflow_step_run.success_count) AS success_total,
                    SUM(workflow_step_run.error_count) AS record_error_total
                 FROM fluxx_workflow_step_run workflow_step_run
                 INNER JOIN (%s) latest_step_run ON latest_step_run.latest_id = workflow_step_run.id
                 GROUP BY workflow_step_run.step_name',
                $latestStepRunsSubquery,
            ),
            [
                ...$baseParams,
                'failedStatus' => WorkflowStepRunStatus::Failed->value,
                'noDeduplicationStatus' => WorkflowStepDeduplicationStatus::None->value,
            ],
            $baseTypes,
        );

        $retryRunCount = (int) $connection->fetchOne(
            sprintf(
                'SELECT COUNT(DISTINCT workflow_step_run.workflow_run_id)
                 FROM fluxx_workflow_step_run workflow_step_run
                 INNER JOIN (%s) latest_step_run ON latest_step_run.latest_id = workflow_step_run.id
                 WHERE workflow_step_run.retry_count > 0',
                $latestStepRunsSubquery,
            ),
            $baseParams,
            $baseTypes,
        );

        $stepStats = [];
        $processedTotal = 0;
        $successTotal = 0;
        $recordErrorTotal = 0;

        foreach ($rows as $row) {
            $stepName = (string) ($row['step_name'] ?? '');

            if ($stepName === '') {
                continue;
            }

            $processedTotal += (int) ($row['processed_total'] ?? 0);
            $successTotal += (int) ($row['success_total'] ?? 0);
            $recordErrorTotal += (int) ($row['record_error_total'] ?? 0);

            $stepStats[$stepName] = [
                'durationTotal' => (int) ($row['duration_total'] ?? 0),
                'durationCount' => (int) ($row['duration_count'] ?? 0),
                'failureCount' => (int) ($row['failure_count'] ?? 0),
                'retryCount' => (int) ($row['retry_count'] ?? 0),
                'idempotenceHitCount' => (int) ($row['idempotence_hit_count'] ?? 0),
                'executionCount' => (int) ($row['execution_count'] ?? 0),
            ];
        }

        return [
            'retryRunCount' => $retryRunCount,
            'processedTotal' => $processedTotal,
            'successTotal' => $successTotal,
            'recordErrorTotal' => $recordErrorTotal,
            'steps' => $stepStats,
        ];
    }

    /**
     * @return array{
     *     retryRunCount: int,
     *     processedTotal: int,
     *     successTotal: int,
     *     recordErrorTotal: int
     * }
     */
    public function aggregateLatestStepStatisticsSinceAll(DateTimeImmutable $startAt): array
    {
        $connection = $this->connection();
        $latestStepRunsSubquery = $this->latestStepRunsSinceAllSubquery();
        $summary = $connection->fetchAssociative(
            sprintf(
                'SELECT
                    SUM(workflow_step_run.processed_count) AS processed_total,
                    SUM(workflow_step_run.success_count) AS success_total,
                    SUM(workflow_step_run.error_count) AS record_error_total
                 FROM fluxx_workflow_step_run workflow_step_run
                 INNER JOIN (%s) latest_step_run ON latest_step_run.latest_id = workflow_step_run.id',
                $latestStepRunsSubquery,
            ),
            [
                'startAt' => $startAt,
            ],
            [
                'startAt' => Types::DATETIME_IMMUTABLE,
            ],
        ) ?: [];

        $retryRunCount = (int) $connection->fetchOne(
            sprintf(
                'SELECT COUNT(DISTINCT workflow_step_run.workflow_run_id)
                 FROM fluxx_workflow_step_run workflow_step_run
                 INNER JOIN (%s) latest_step_run ON latest_step_run.latest_id = workflow_step_run.id
                 WHERE workflow_step_run.retry_count > 0',
                $latestStepRunsSubquery,
            ),
            [
                'startAt' => $startAt,
            ],
            [
                'startAt' => Types::DATETIME_IMMUTABLE,
            ],
        );

        return [
            'retryRunCount' => $retryRunCount,
            'processedTotal' => (int) ($summary['processed_total'] ?? 0),
            'successTotal' => (int) ($summary['success_total'] ?? 0),
            'recordErrorTotal' => (int) ($summary['record_error_total'] ?? 0),
        ];
    }

    private function latestStepRunsByWorkflowSinceSubquery(): string
    {
        return <<<'SQL'
            SELECT latest_step_run.latest_id
            FROM (
                SELECT
                    workflow_step_run.workflow_run_id,
                    workflow_step_run.step_name,
                    MAX(workflow_step_run.id) AS latest_id
                FROM fluxx_workflow_step_run workflow_step_run
                INNER JOIN fluxx_workflow_run workflow_run ON workflow_run.id = workflow_step_run.workflow_run_id
                WHERE workflow_run.workflow_name = :workflowName
                  AND workflow_run.created_at >= :startAt
                GROUP BY workflow_step_run.workflow_run_id, workflow_step_run.step_name
            ) latest_step_run
        SQL;
    }

    private function latestStepRunsSinceAllSubquery(): string
    {
        return <<<'SQL'
            SELECT latest_step_run.latest_id
            FROM (
                SELECT
                    workflow_step_run.workflow_run_id,
                    workflow_step_run.step_name,
                    MAX(workflow_step_run.id) AS latest_id
                FROM fluxx_workflow_step_run workflow_step_run
                INNER JOIN fluxx_workflow_run workflow_run ON workflow_run.id = workflow_step_run.workflow_run_id
                WHERE workflow_run.created_at >= :startAt
                GROUP BY workflow_step_run.workflow_run_id, workflow_step_run.step_name
            ) latest_step_run
        SQL;
    }

    /**
     * @param list<WorkflowRun> $workflowRuns
     * @return array{0: list<int>, 1: array<int, string>}
     */
    private function extractWorkflowRunIdentifiers(array $workflowRuns): array
    {
        $workflowRunIds = [];
        $runIdsByDatabaseId = [];

        foreach ($workflowRuns as $workflowRun) {
            $databaseId = $workflowRun->id();

            if ($databaseId === null) {
                continue;
            }

            $workflowRunIds[] = $databaseId;
            $runIdsByDatabaseId[$databaseId] = $workflowRun->runId();
        }

        return [array_values(array_unique($workflowRunIds)), $runIdsByDatabaseId];
    }

    private function toDateTimeImmutable(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return new DateTimeImmutable($value);
    }

    private function connection(): Connection
    {
        $manager = $this->registry->getManagerForClass(WorkflowStepRun::class);

        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException('No entity manager is configured for Fluxx workflow step runs.');
        }

        return $manager->getConnection();
    }
}
