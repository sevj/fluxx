<?php

declare(strict_types=1);

namespace Fluxx\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Fluxx\Entity\Enum\WorkflowStepDeduplicationStatus;
use Fluxx\Entity\Enum\WorkflowStepRunStatus;
use Fluxx\Entity\Enum\WorkflowRunStatus;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;

/**
 * @extends ServiceEntityRepository<WorkflowStepRun>
 */
final class WorkflowStepRunRepository extends ServiceEntityRepository implements WorkflowStepRunLookupInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowStepRun::class);
    }

    /**
     * @return list<WorkflowStepRun>
     */
    public function findByWorkflowRunOrdered(WorkflowRun $workflowRun): array
    {
        return $this->createQueryBuilder('workflow_step_run')
            ->andWhere('workflow_step_run.workflowRun = :workflowRun')
            ->setParameter('workflowRun', $workflowRun)
            ->orderBy('workflow_step_run.position', 'ASC')
            ->addOrderBy('workflow_step_run.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findLatestByWorkflowRunAndType(
        WorkflowRun $workflowRun,
        string $stepType,
    ): ?WorkflowStepRun {
        /** @var WorkflowStepRun|null $stepRun */
        $stepRun = $this->createQueryBuilder('workflow_step_run')
            ->andWhere('workflow_step_run.workflowRun = :workflowRun')
            ->andWhere('workflow_step_run.stepType = :stepType')
            ->setParameter('workflowRun', $workflowRun)
            ->setParameter('stepType', $stepType)
            ->orderBy('workflow_step_run.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $stepRun;
    }

    public function findLatestByWorkflowRunAndStepName(WorkflowRun $workflowRun, string $stepName): ?WorkflowStepRun
    {
        /** @var WorkflowStepRun|null $stepRun */
        $stepRun = $this->createQueryBuilder('workflow_step_run')
            ->andWhere('workflow_step_run.workflowRun = :workflowRun')
            ->andWhere('workflow_step_run.stepName = :stepName')
            ->setParameter('workflowRun', $workflowRun)
            ->setParameter('stepName', $stepName)
            ->orderBy('workflow_step_run.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $stepRun;
    }

    /**
     * @param list<string> $stepNames
     * @return array<string, WorkflowStepRun>
     */
    public function findCompletedByWorkflowRunAndStepNames(WorkflowRun $workflowRun, array $stepNames): array
    {
        if ($stepNames === []) {
            return [];
        }

        /** @var list<WorkflowStepRun> $stepRuns */
        $stepRuns = $this->createQueryBuilder('workflow_step_run')
            ->andWhere('workflow_step_run.workflowRun = :workflowRun')
            ->andWhere('workflow_step_run.stepName IN (:stepNames)')
            ->andWhere('workflow_step_run.status = :status')
            ->setParameter('workflowRun', $workflowRun)
            ->setParameter('stepNames', $stepNames)
            ->setParameter('status', WorkflowStepRunStatus::Completed)
            ->orderBy('workflow_step_run.id', 'DESC')
            ->getQuery()
            ->getResult();

        $grouped = [];

        foreach ($stepRuns as $stepRun) {
            $grouped[$stepRun->stepName()] ??= $stepRun;
        }

        return $grouped;
    }

    /**
     * @param list<WorkflowRun> $workflowRuns
     * @return array<string, list<WorkflowStepRun>>
     */
    public function findByWorkflowRunsGrouped(array $workflowRuns): array
    {
        if ($workflowRuns === []) {
            return [];
        }

        /** @var list<WorkflowStepRun> $stepRuns */
        $stepRuns = $this->createQueryBuilder('workflow_step_run')
            ->andWhere('workflow_step_run.workflowRun IN (:workflowRuns)')
            ->setParameter('workflowRuns', $workflowRuns)
            ->orderBy('workflow_step_run.position', 'ASC')
            ->addOrderBy('workflow_step_run.id', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];

        foreach ($stepRuns as $stepRun) {
            $grouped[$stepRun->workflowRun()->runId()][] = $stepRun;
        }

        return $grouped;
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

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
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

        $summary = $this->getEntityManager()->getConnection()->fetchAssociative(
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

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
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
        $connection = $this->getEntityManager()->getConnection();
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
        $connection = $this->getEntityManager()->getConnection();
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

    public function findOneByWorkflowNameRunIdAndStepName(string $workflowName, string $runId, string $stepName): ?WorkflowStepRun
    {
        /** @var WorkflowStepRun|null $stepRun */
        $stepRun = $this->createQueryBuilder('workflow_step_run')
            ->innerJoin('workflow_step_run.workflowRun', 'workflow_run')
            ->addSelect('workflow_run')
            ->andWhere('workflow_run.workflowName = :workflowName')
            ->andWhere('workflow_run.runId = :runId')
            ->andWhere('workflow_step_run.stepName = :stepName')
            ->setParameter('workflowName', $workflowName)
            ->setParameter('runId', $runId)
            ->setParameter('stepName', $stepName)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $stepRun;
    }

    public function findLatestCompletedByWorkflowNameAndStepNameAndIdempotenceKey(
        string $workflowName,
        string $stepName,
        string $idempotenceKey,
    ): ?WorkflowStepRun {
        /** @var WorkflowStepRun|null $stepRun */
        $stepRun = $this->createQueryBuilder('workflow_step_run')
            ->innerJoin('workflow_step_run.workflowRun', 'workflow_run')
            ->addSelect('workflow_run')
            ->andWhere('workflow_run.workflowName = :workflowName')
            ->andWhere('workflow_step_run.stepName = :stepName')
            ->andWhere('workflow_step_run.idempotenceKey = :idempotenceKey')
            ->andWhere('workflow_step_run.status = :status')
            ->setParameter('workflowName', $workflowName)
            ->setParameter('stepName', $stepName)
            ->setParameter('idempotenceKey', $idempotenceKey)
            ->setParameter('status', WorkflowStepRunStatus::Completed)
            ->orderBy('workflow_step_run.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $stepRun;
    }

    /**
     * @param list<string> $workflowNames
     * @return list<WorkflowStepRun>
     */
    public function findFailedByWorkflowNames(array $workflowNames): array
    {
        if ($workflowNames === []) {
            return [];
        }

        /** @var list<WorkflowStepRun> $stepRuns */
        return $this->createQueryBuilder('workflow_step_run')
            ->innerJoin('workflow_step_run.workflowRun', 'workflow_run')
            ->addSelect('workflow_run')
            ->andWhere('workflow_run.workflowName IN (:workflowNames)')
            ->andWhere('workflow_step_run.status = :stepStatus')
            ->andWhere('workflow_run.status IN (:runStatuses)')
            ->setParameter('workflowNames', array_values(array_unique($workflowNames)))
            ->setParameter('stepStatus', WorkflowStepRunStatus::Failed)
            ->setParameter('runStatuses', [
                WorkflowRunStatus::Failed,
                WorkflowRunStatus::PartiallyFailed,
            ])
            ->orderBy('workflow_run.workflowName', 'ASC')
            ->addOrderBy('workflow_step_run.finishedAt', 'DESC')
            ->addOrderBy('workflow_run.createdAt', 'DESC')
            ->addOrderBy('workflow_step_run.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<string> $workflowNames
     * @param list<string> $matchedWorkflowCodes
     * @param array<string, list<string>> $matchedStepCodesByWorkflowCode
     */
    public function countTroubleshootingIssuesByWorkflowNames(
        array $workflowNames,
        string $searchQuery = '',
        array $matchedWorkflowCodes = [],
        array $matchedStepCodesByWorkflowCode = [],
    ): int {
        if ($workflowNames === []) {
            return 0;
        }

        [$searchSql, $params, $types] = $this->buildTroubleshootingSearchClause(
            $workflowNames,
            $searchQuery,
            $matchedWorkflowCodes,
            $matchedStepCodesByWorkflowCode,
        );

        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            sprintf(
                'SELECT COUNT(*)
                 FROM (%s) failed_issue
                 INNER JOIN fluxx_workflow_step_run workflow_step_run ON workflow_step_run.id = failed_issue.latest_failed_step_run_id
                 INNER JOIN fluxx_workflow_run workflow_run ON workflow_run.id = workflow_step_run.workflow_run_id
                 %s',
                $this->failedTroubleshootingIssueSubquery(),
                $searchSql,
            ),
            $params,
            $types,
        );
    }

    /**
     * @param list<string> $workflowNames
     * @param list<string> $matchedWorkflowCodes
     * @param array<string, list<string>> $matchedStepCodesByWorkflowCode
     * @return list<array{
     *     workflowCode: string,
     *     sourceSystem: string,
     *     targetSystem: string,
     *     runId: string,
     *     stepCode: string,
     *     failedAt: ?DateTimeImmutable,
     *     errorMessage: string,
     *     failureCount: int
     * }>
     */
    public function findTroubleshootingIssueRowsByWorkflowNames(
        array $workflowNames,
        int $limit,
        int $offset,
        string $searchQuery = '',
        array $matchedWorkflowCodes = [],
        array $matchedStepCodesByWorkflowCode = [],
    ): array {
        if ($workflowNames === []) {
            return [];
        }

        [$searchSql, $params, $types] = $this->buildTroubleshootingSearchClause(
            $workflowNames,
            $searchQuery,
            $matchedWorkflowCodes,
            $matchedStepCodesByWorkflowCode,
        );

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            sprintf(
                'SELECT
                    workflow_run.workflow_name AS workflow_code,
                    workflow_run.source_system,
                    workflow_run.target_system,
                    workflow_run.run_id,
                    workflow_step_run.step_name,
                    COALESCE(workflow_step_run.finished_at, workflow_step_run.created_at) AS failed_at,
                    COALESCE(NULLIF(TRIM(workflow_step_run.error_message), \'\'), \'No error message stored.\') AS error_message,
                    failed_issue.failure_count
                 FROM (%s) failed_issue
                 INNER JOIN fluxx_workflow_step_run workflow_step_run ON workflow_step_run.id = failed_issue.latest_failed_step_run_id
                 INNER JOIN fluxx_workflow_run workflow_run ON workflow_run.id = workflow_step_run.workflow_run_id
                 %s
                 ORDER BY COALESCE(workflow_step_run.finished_at, workflow_step_run.created_at) DESC, workflow_run.workflow_name ASC, workflow_step_run.step_name ASC
                 LIMIT :limit OFFSET :offset',
                $this->failedTroubleshootingIssueSubquery(),
                $searchSql,
            ),
            [
                ...$params,
                'limit' => max($limit, 1),
                'offset' => max($offset, 0),
            ],
            [
                ...$types,
                'limit' => Types::INTEGER,
                'offset' => Types::INTEGER,
            ],
        );

        return array_values(array_filter(array_map(
            static function (array $row): ?array {
                $workflowCode = (string) ($row['workflow_code'] ?? '');
                $runId = (string) ($row['run_id'] ?? '');
                $stepCode = (string) ($row['step_name'] ?? '');

                if ($workflowCode === '' || $runId === '' || $stepCode === '') {
                    return null;
                }

                return [
                    'workflowCode' => $workflowCode,
                    'sourceSystem' => (string) ($row['source_system'] ?? ''),
                    'targetSystem' => (string) ($row['target_system'] ?? ''),
                    'runId' => $runId,
                    'stepCode' => $stepCode,
                    'failedAt' => isset($row['failed_at']) && is_string($row['failed_at']) && $row['failed_at'] !== ''
                        ? new DateTimeImmutable($row['failed_at'])
                        : null,
                    'errorMessage' => (string) ($row['error_message'] ?? 'No error message stored.'),
                    'failureCount' => (int) ($row['failure_count'] ?? 0),
                ];
            },
            $rows,
        )));
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

    private function failedTroubleshootingIssueSubquery(): string
    {
        return <<<'SQL'
            SELECT
                workflow_run.workflow_name,
                workflow_run.run_id,
                workflow_step_run.step_name,
                MAX(workflow_step_run.id) AS latest_failed_step_run_id,
                COUNT(*) AS failure_count
            FROM fluxx_workflow_step_run workflow_step_run
            INNER JOIN fluxx_workflow_run workflow_run ON workflow_run.id = workflow_step_run.workflow_run_id
            WHERE workflow_run.workflow_name IN (:workflowNames)
              AND workflow_step_run.status = :stepStatus
              AND workflow_run.status IN (:runStatuses)
            GROUP BY workflow_run.workflow_name, workflow_run.run_id, workflow_step_run.step_name
        SQL;
    }

    /**
     * @param list<string> $workflowNames
     * @param list<string> $matchedWorkflowCodes
     * @param array<string, list<string>> $matchedStepCodesByWorkflowCode
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function buildTroubleshootingSearchClause(
        array $workflowNames,
        string $searchQuery,
        array $matchedWorkflowCodes,
        array $matchedStepCodesByWorkflowCode,
    ): array {
        $params = [
            'workflowNames' => array_values(array_unique($workflowNames)),
            'stepStatus' => WorkflowStepRunStatus::Failed->value,
            'runStatuses' => [
                WorkflowRunStatus::Failed->value,
                WorkflowRunStatus::PartiallyFailed->value,
            ],
        ];
        $types = [
            'workflowNames' => ArrayParameterType::STRING,
            'runStatuses' => ArrayParameterType::STRING,
        ];

        $searchQuery = trim($searchQuery);

        if ($searchQuery === '') {
            return ['', $params, $types];
        }

        $clauses = [
            'LOWER(workflow_run.workflow_name) LIKE :search',
            'LOWER(workflow_run.source_system) LIKE :search',
            'LOWER(workflow_run.target_system) LIKE :search',
            'LOWER(workflow_run.run_id) LIKE :search',
            'LOWER(workflow_step_run.step_name) LIKE :search',
            'LOWER(COALESCE(workflow_step_run.error_message, \'\')) LIKE :search',
        ];
        $params['search'] = '%' . mb_strtolower($searchQuery) . '%';

        $matchedWorkflowCodes = array_values(array_unique($matchedWorkflowCodes));

        if ($matchedWorkflowCodes !== []) {
            $clauses[] = 'workflow_run.workflow_name IN (:matchedWorkflowCodes)';
            $params['matchedWorkflowCodes'] = $matchedWorkflowCodes;
            $types['matchedWorkflowCodes'] = ArrayParameterType::STRING;
        }

        $stepMatchIndex = 0;

        foreach ($matchedStepCodesByWorkflowCode as $workflowCode => $stepCodes) {
            $stepCodes = array_values(array_unique($stepCodes));

            if ($stepCodes === []) {
                continue;
            }

            $workflowParam = sprintf('matchedStepWorkflowCode%d', $stepMatchIndex);
            $stepsParam = sprintf('matchedStepCodes%d', $stepMatchIndex);
            $clauses[] = sprintf('(workflow_run.workflow_name = :%s AND workflow_step_run.step_name IN (:%s))', $workflowParam, $stepsParam);
            $params[$workflowParam] = $workflowCode;
            $params[$stepsParam] = $stepCodes;
            $types[$stepsParam] = ArrayParameterType::STRING;
            ++$stepMatchIndex;
        }

        return [
            ' WHERE (' . implode(' OR ', $clauses) . ')',
            $params,
            $types,
        ];
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
}
