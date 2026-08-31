<?php

declare(strict_types=1);

namespace Fluxx\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Types;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Fluxx\Entity\Enum\WorkflowRunStatus;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Ui\WorkflowRunFilters;

/**
 * @extends ServiceEntityRepository<WorkflowRun>
 */
final class WorkflowRunRepository extends ServiceEntityRepository implements WorkflowRunLookupInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowRun::class);
    }

    public function findOneByRunId(string $runId): ?WorkflowRun
    {
        return $this->findOneBy(['runId' => $runId]);
    }

    /**
     * @return array{status: string, metadata: array<string, mixed>, finishedAt: ?DateTimeImmutable}|null
     */
    public function findPersistedRunStateByRunId(string $runId): ?array
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT status, metadata, finished_at FROM fluxx_workflow_run WHERE run_id = :runId',
            ['runId' => $runId],
        );

        if (!is_array($row)) {
            return null;
        }

        $metadata = json_decode((string) ($row['metadata'] ?? '[]'), true);

        return [
            'status' => (string) ($row['status'] ?? ''),
            'metadata' => is_array($metadata) ? $metadata : [],
            'finishedAt' => isset($row['finished_at']) && is_string($row['finished_at']) && $row['finished_at'] !== ''
                ? new DateTimeImmutable($row['finished_at'])
                : null,
        ];
    }

    /**
     * @param list<string> $runIds
     * @return array<string, WorkflowRun>
     */
    public function findByRunIdsIndexed(array $runIds): array
    {
        if ($runIds === []) {
            return [];
        }

        /** @var list<WorkflowRun> $runs */
        $runs = $this->createQueryBuilder('workflow_run')
            ->andWhere('workflow_run.runId IN (:runIds)')
            ->setParameter('runIds', array_values(array_unique($runIds)))
            ->getQuery()
            ->getResult();

        $indexed = [];

        foreach ($runs as $run) {
            $indexed[$run->runId()] = $run;
        }

        return $indexed;
    }

    /**
     * @return list<WorkflowRun>
     */
    public function findLatestByWorkflowName(string $workflowName, int $limit = 20): array
    {
        return $this->createQueryBuilder('workflow_run')
            ->andWhere('workflow_run.workflowName = :workflowName')
            ->setParameter('workflowName', $workflowName)
            ->orderBy('workflow_run.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countByWorkflowName(string $workflowName): int
    {
        return (int) $this->createQueryBuilder('workflow_run')
            ->select('COUNT(workflow_run.id)')
            ->andWhere('workflow_run.workflowName = :workflowName')
            ->setParameter('workflowName', $workflowName)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findLatestOneByWorkflowName(string $workflowName): ?WorkflowRun
    {
        /** @var WorkflowRun|null $workflowRun */
        $workflowRun = $this->createQueryBuilder('workflow_run')
            ->andWhere('workflow_run.workflowName = :workflowName')
            ->setParameter('workflowName', $workflowName)
            ->orderBy('workflow_run.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $workflowRun;
    }

    /**
     * @return list<WorkflowRun>
     */
    public function findPaginatedByWorkflowName(string $workflowName, int $limit, int $offset): array
    {
        return $this->createQueryBuilder('workflow_run')
            ->andWhere('workflow_run.workflowName = :workflowName')
            ->setParameter('workflowName', $workflowName)
            ->orderBy('workflow_run.createdAt', 'DESC')
            ->addOrderBy('workflow_run.id', 'DESC')
            ->setFirstResult(max($offset, 0))
            ->setMaxResults(max($limit, 1))
            ->getQuery()
            ->getResult();
    }

    public function countErroredByWorkflowName(string $workflowName): int
    {
        return (int) $this->createQueryBuilder('workflow_run')
            ->select('COUNT(workflow_run.id)')
            ->andWhere('workflow_run.workflowName = :workflowName')
            ->andWhere('workflow_run.status IN (:statuses)')
            ->setParameter('workflowName', $workflowName)
            ->setParameter('statuses', [
                WorkflowRunStatus::Failed,
                WorkflowRunStatus::PartiallyFailed,
            ])
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findLatestErrorAtByWorkflowName(string $workflowName): ?DateTimeImmutable
    {
        /** @var WorkflowRun|null $workflowRun */
        $workflowRun = $this->createQueryBuilder('workflow_run')
            ->andWhere('workflow_run.workflowName = :workflowName')
            ->andWhere('workflow_run.status IN (:statuses)')
            ->setParameter('workflowName', $workflowName)
            ->setParameter('statuses', [
                WorkflowRunStatus::Failed,
                WorkflowRunStatus::PartiallyFailed,
            ])
            ->orderBy('workflow_run.finishedAt', 'DESC')
            ->addOrderBy('workflow_run.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $workflowRun?->finishedAt() ?? $workflowRun?->createdAt();
    }

    /**
     * @return list<WorkflowRun>
     */
    public function findCreatedSinceByWorkflowName(string $workflowName, DateTimeImmutable $startAt): array
    {
        return $this->createQueryBuilder('workflow_run')
            ->andWhere('workflow_run.workflowName = :workflowName')
            ->andWhere('workflow_run.createdAt >= :startAt')
            ->setParameter('workflowName', $workflowName)
            ->setParameter('startAt', $startAt)
            ->orderBy('workflow_run.createdAt', 'ASC')
            ->addOrderBy('workflow_run.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<string, array{executionCount: int, errorCount: int}>
     */
    public function aggregateCreatedSinceByWorkflowName(string $workflowName, DateTimeImmutable $startAt, string $bucket): array
    {
        $connection = $this->getEntityManager()->getConnection();
        $bucketExpression = $this->bucketExpression('created_at', $bucket);
        $rows = $connection->fetchAllAssociative(
            sprintf(
                'SELECT %1$s AS bucket_key, COUNT(*) AS execution_count, SUM(CASE WHEN status IN (:errorStatuses) THEN 1 ELSE 0 END) AS error_count
                 FROM fluxx_workflow_run
                 WHERE workflow_name = :workflowName
                   AND created_at >= :startAt
                 GROUP BY %1$s',
                $bucketExpression,
            ),
            [
                'workflowName' => $workflowName,
                'startAt' => $startAt,
                'errorStatuses' => [
                    WorkflowRunStatus::Failed->value,
                    WorkflowRunStatus::PartiallyFailed->value,
                ],
            ],
            [
                'startAt' => Types::DATETIME_IMMUTABLE,
                'errorStatuses' => ArrayParameterType::STRING,
            ],
        );

        $bucketStats = [];

        foreach ($rows as $row) {
            $bucketKey = (string) ($row['bucket_key'] ?? '');

            if ($bucketKey === '') {
                continue;
            }

            $bucketStats[$bucketKey] = [
                'executionCount' => (int) ($row['execution_count'] ?? 0),
                'errorCount' => (int) ($row['error_count'] ?? 0),
            ];
        }

        return $bucketStats;
    }

    /**
     * @return array{
     *     runCount: int,
     *     failedCount: int,
     *     partialFailedCount: int,
     *     relaunchCount: int,
     *     durations: list<int>
     * }
     */
    public function summarizeCreatedSinceByWorkflowName(string $workflowName, DateTimeImmutable $startAt): array
    {
        $connection = $this->getEntityManager()->getConnection();
        $metadataTextExpression = $this->jsonTextExpression('metadata');
        $summary = $connection->fetchAssociative(
            sprintf(
                'SELECT
                    COUNT(*) AS run_count,
                    SUM(CASE WHEN status = :failedStatus THEN 1 ELSE 0 END) AS failed_count,
                    SUM(CASE WHEN status = :partialFailedStatus THEN 1 ELSE 0 END) AS partial_failed_count,
                    SUM(CASE WHEN %s LIKE :relaunchMarker THEN 1 ELSE 0 END) AS relaunch_count
                 FROM fluxx_workflow_run
                 WHERE workflow_name = :workflowName
                   AND created_at >= :startAt',
                $metadataTextExpression,
            ),
            [
                'workflowName' => $workflowName,
                'startAt' => $startAt,
                'failedStatus' => WorkflowRunStatus::Failed->value,
                'partialFailedStatus' => WorkflowRunStatus::PartiallyFailed->value,
                'relaunchMarker' => '%"relaunch":%',
            ],
            [
                'startAt' => Types::DATETIME_IMMUTABLE,
            ],
        ) ?: [];

        $durations = array_map(
            static fn (mixed $durationMs): int => max((int) $durationMs, 0),
            $connection->fetchFirstColumn(
                sprintf(
                    'SELECT %s AS duration_ms
                     FROM fluxx_workflow_run
                     WHERE workflow_name = :workflowName
                       AND created_at >= :startAt
                       AND started_at IS NOT NULL
                       AND finished_at IS NOT NULL',
                    $this->durationExpression('started_at', 'finished_at'),
                ),
                [
                    'workflowName' => $workflowName,
                    'startAt' => $startAt,
                ],
                [
                    'startAt' => Types::DATETIME_IMMUTABLE,
                ],
            ),
        );

        return [
            'runCount' => (int) ($summary['run_count'] ?? 0),
            'failedCount' => (int) ($summary['failed_count'] ?? 0),
            'partialFailedCount' => (int) ($summary['partial_failed_count'] ?? 0),
            'relaunchCount' => (int) ($summary['relaunch_count'] ?? 0),
            'durations' => $durations,
        ];
    }

    /**
     * @param list<string> $workflowNames
     * @return array<string, array{
     *     executionCount: int,
     *     errorCount: int,
     *     lastExecutionAt: ?DateTimeImmutable,
     *     lastErrorAt: ?DateTimeImmutable
     * }>
     */
    public function summarizeByWorkflowNames(array $workflowNames): array
    {
        $workflowNames = array_values(array_unique(array_filter(
            $workflowNames,
            static fn (string $workflowName): bool => $workflowName !== '',
        )));

        if ($workflowNames === []) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT
                workflow_name,
                COUNT(*) AS execution_count,
                SUM(CASE WHEN status IN (:errorStatuses) THEN 1 ELSE 0 END) AS error_count,
                MAX(created_at) AS last_execution_at,
                MAX(CASE WHEN status IN (:errorStatuses) THEN COALESCE(finished_at, created_at) ELSE NULL END) AS last_error_at
             FROM fluxx_workflow_run
             WHERE workflow_name IN (:workflowNames)
             GROUP BY workflow_name',
            [
                'workflowNames' => $workflowNames,
                'errorStatuses' => [
                    WorkflowRunStatus::Failed->value,
                    WorkflowRunStatus::PartiallyFailed->value,
                ],
            ],
            [
                'workflowNames' => ArrayParameterType::STRING,
                'errorStatuses' => ArrayParameterType::STRING,
            ],
        );

        $summary = [];

        foreach ($rows as $row) {
            $workflowName = (string) ($row['workflow_name'] ?? '');

            if ($workflowName === '') {
                continue;
            }

            $summary[$workflowName] = [
                'executionCount' => (int) ($row['execution_count'] ?? 0),
                'errorCount' => (int) ($row['error_count'] ?? 0),
                'lastExecutionAt' => $this->toDateTimeImmutable($row['last_execution_at'] ?? null),
                'lastErrorAt' => $this->toDateTimeImmutable($row['last_error_at'] ?? null),
            ];
        }

        return $summary;
    }

    public function countByFilters(WorkflowRunFilters $filters): int
    {
        return (int) $this->createFilteredQueryBuilder($filters)
            ->select('COUNT(workflow_run.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return array<string, array{executionCount: int, errorCount: int}>
     */
    public function aggregateCreatedSinceAll(DateTimeImmutable $startAt, string $bucket): array
    {
        $connection = $this->getEntityManager()->getConnection();
        $bucketExpression = $this->bucketExpression('created_at', $bucket);
        $rows = $connection->fetchAllAssociative(
            sprintf(
                'SELECT %1$s AS bucket_key, COUNT(*) AS execution_count, SUM(CASE WHEN status IN (:errorStatuses) THEN 1 ELSE 0 END) AS error_count
                 FROM fluxx_workflow_run
                 WHERE created_at >= :startAt
                 GROUP BY %1$s',
                $bucketExpression,
            ),
            [
                'startAt' => $startAt,
                'errorStatuses' => [
                    WorkflowRunStatus::Failed->value,
                    WorkflowRunStatus::PartiallyFailed->value,
                ],
            ],
            [
                'startAt' => Types::DATETIME_IMMUTABLE,
                'errorStatuses' => ArrayParameterType::STRING,
            ],
        );

        $bucketStats = [];

        foreach ($rows as $row) {
            $bucketKey = (string) ($row['bucket_key'] ?? '');

            if ($bucketKey === '') {
                continue;
            }

            $bucketStats[$bucketKey] = [
                'executionCount' => (int) ($row['execution_count'] ?? 0),
                'errorCount' => (int) ($row['error_count'] ?? 0),
            ];
        }

        return $bucketStats;
    }

    /**
     * @return array{
     *     runCount: int,
     *     failedCount: int,
     *     partialFailedCount: int,
     *     relaunchCount: int,
     *     durations: list<int>
     * }
     */
    public function summarizeCreatedSinceAll(DateTimeImmutable $startAt): array
    {
        $connection = $this->getEntityManager()->getConnection();
        $metadataTextExpression = $this->jsonTextExpression('metadata');
        $summary = $connection->fetchAssociative(
            sprintf(
                'SELECT
                    COUNT(*) AS run_count,
                    SUM(CASE WHEN status = :failedStatus THEN 1 ELSE 0 END) AS failed_count,
                    SUM(CASE WHEN status = :partialFailedStatus THEN 1 ELSE 0 END) AS partial_failed_count,
                    SUM(CASE WHEN %s LIKE :relaunchMarker THEN 1 ELSE 0 END) AS relaunch_count
                 FROM fluxx_workflow_run
                 WHERE created_at >= :startAt',
                $metadataTextExpression,
            ),
            [
                'startAt' => $startAt,
                'failedStatus' => WorkflowRunStatus::Failed->value,
                'partialFailedStatus' => WorkflowRunStatus::PartiallyFailed->value,
                'relaunchMarker' => '%"relaunch":%',
            ],
            [
                'startAt' => Types::DATETIME_IMMUTABLE,
            ],
        ) ?: [];

        $durations = array_map(
            static fn (mixed $durationMs): int => max((int) $durationMs, 0),
            $connection->fetchFirstColumn(
                sprintf(
                    'SELECT %s AS duration_ms
                     FROM fluxx_workflow_run
                     WHERE created_at >= :startAt
                       AND started_at IS NOT NULL
                       AND finished_at IS NOT NULL',
                    $this->durationExpression('started_at', 'finished_at'),
                ),
                [
                    'startAt' => $startAt,
                ],
                [
                    'startAt' => Types::DATETIME_IMMUTABLE,
                ],
            ),
        );

        return [
            'runCount' => (int) ($summary['run_count'] ?? 0),
            'failedCount' => (int) ($summary['failed_count'] ?? 0),
            'partialFailedCount' => (int) ($summary['partial_failed_count'] ?? 0),
            'relaunchCount' => (int) ($summary['relaunch_count'] ?? 0),
            'durations' => $durations,
        ];
    }

    /**
     * @return list<WorkflowRun>
     */
    public function findPaginatedByFilters(WorkflowRunFilters $filters, int $limit, int $offset): array
    {
        return $this->createFilteredQueryBuilder($filters)
            ->orderBy('workflow_run.createdAt', 'DESC')
            ->addOrderBy('workflow_run.id', 'DESC')
            ->setFirstResult(max($offset, 0))
            ->setMaxResults(max($limit, 1))
            ->getQuery()
            ->getResult();
    }

    private function createFilteredQueryBuilder(WorkflowRunFilters $filters): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('workflow_run');
        $errorStatuses = [
            WorkflowRunStatus::Failed,
            WorkflowRunStatus::PartiallyFailed,
        ];

        if ($filters->searchQuery() !== '') {
            $queryBuilder
                ->andWhere('
                    LOWER(workflow_run.runId) LIKE :search
                    OR LOWER(workflow_run.workflowName) LIKE :search
                    OR LOWER(workflow_run.sourceSystem) LIKE :search
                    OR LOWER(workflow_run.targetSystem) LIKE :search
                    OR LOWER(workflow_run.trigger) LIKE :search
                    OR LOWER(COALESCE(workflow_run.batchId, \'\')) LIKE :search
                    OR LOWER(COALESCE(workflow_run.errorMessage, \'\')) LIKE :search
                    OR LOWER(COALESCE(workflow_run.lockKey, \'\')) LIKE :search
                ')
                ->setParameter('search', '%' . mb_strtolower($filters->searchQuery()) . '%');
        }

        if ($filters->workflowCode() !== null) {
            $queryBuilder
                ->andWhere('workflow_run.workflowName = :workflowName')
                ->setParameter('workflowName', $filters->workflowCode());
        }

        if ($filters->status() !== null) {
            $queryBuilder
                ->andWhere('workflow_run.status = :status')
                ->setParameter('status', $filters->status());
        }

        if ($filters->sourceSystem() !== null) {
            $queryBuilder
                ->andWhere('workflow_run.sourceSystem = :sourceSystem')
                ->setParameter('sourceSystem', $filters->sourceSystem());
        }

        if ($filters->targetSystem() !== null) {
            $queryBuilder
                ->andWhere('workflow_run.targetSystem = :targetSystem')
                ->setParameter('targetSystem', $filters->targetSystem());
        }

        if ($filters->errorPresence() === 'with') {
            $queryBuilder
                ->andWhere('workflow_run.status IN (:errorStatuses)')
                ->setParameter('errorStatuses', $errorStatuses);
        } elseif ($filters->errorPresence() === 'without') {
            $queryBuilder
                ->andWhere('workflow_run.status NOT IN (:errorStatuses)')
                ->setParameter('errorStatuses', $errorStatuses);
        }

        if ($filters->dateFrom() !== null) {
            $queryBuilder
                ->andWhere('workflow_run.createdAt >= :dateFrom')
                ->setParameter('dateFrom', $filters->dateFrom());
        }

        if ($filters->dateTo() !== null) {
            $queryBuilder
                ->andWhere('workflow_run.createdAt <= :dateTo')
                ->setParameter('dateTo', $filters->dateTo());
        }

        return $queryBuilder;
    }

    private function bucketExpression(string $column, string $bucket): string
    {
        $platform = $this->getEntityManager()->getConnection()->getDatabasePlatform();
        $format = $bucket === 'month' ? '%Y-%m' : '%Y-%m-%d';

        if ($platform instanceof PostgreSQLPlatform) {
            return sprintf(
                "TO_CHAR(%s, '%s')",
                $column,
                $bucket === 'month' ? 'YYYY-MM' : 'YYYY-MM-DD',
            );
        }

        if ($platform instanceof SQLitePlatform) {
            return sprintf("strftime('%s', %s)", $format, $column);
        }

        if ($platform instanceof AbstractMySQLPlatform) {
            return sprintf("DATE_FORMAT(%s, '%s')", $column, $format);
        }

        throw new \RuntimeException('Unsupported database platform for workflow statistics bucketing.');
    }

    private function durationExpression(string $startedAtColumn, string $finishedAtColumn): string
    {
        $platform = $this->getEntityManager()->getConnection()->getDatabasePlatform();

        if ($platform instanceof PostgreSQLPlatform) {
            return sprintf('GREATEST(FLOOR(EXTRACT(EPOCH FROM (%s - %s)) * 1000), 0)', $finishedAtColumn, $startedAtColumn);
        }

        if ($platform instanceof SQLitePlatform) {
            return sprintf('MAX(CAST((julianday(%s) - julianday(%s)) * 86400000 AS INTEGER), 0)', $finishedAtColumn, $startedAtColumn);
        }

        if ($platform instanceof AbstractMySQLPlatform) {
            return sprintf('GREATEST(TIMESTAMPDIFF(MICROSECOND, %s, %s) DIV 1000, 0)', $startedAtColumn, $finishedAtColumn);
        }

        throw new \RuntimeException('Unsupported database platform for workflow statistics durations.');
    }

    private function jsonTextExpression(string $column): string
    {
        $platform = $this->getEntityManager()->getConnection()->getDatabasePlatform();

        if ($platform instanceof PostgreSQLPlatform) {
            return sprintf('CAST(%s AS TEXT)', $column);
        }

        if ($platform instanceof SQLitePlatform) {
            return sprintf('CAST(%s AS TEXT)', $column);
        }

        if ($platform instanceof AbstractMySQLPlatform) {
            return sprintf('CAST(%s AS CHAR)', $column);
        }

        throw new \RuntimeException('Unsupported database platform for workflow statistics JSON casting.');
    }

    private function toDateTimeImmutable(mixed $value): ?DateTimeImmutable
    {
        if (!$value instanceof \DateTimeInterface && (!is_string($value) || $value === '')) {
            return null;
        }

        return $value instanceof DateTimeImmutable
            ? $value
            : new DateTimeImmutable($value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : $value);
    }
}
