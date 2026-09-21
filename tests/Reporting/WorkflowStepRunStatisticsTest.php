<?php

declare(strict_types=1);

namespace Fluxx\Tests\Reporting;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;
use Fluxx\Reporting\WorkflowStepRunStatistics;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class WorkflowStepRunStatisticsTest extends TestCase
{
    #[Test]
    public function it_fetches_only_latest_requested_step_runs_per_run(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(
                self::stringContains('MAX(id) AS latest_id'),
                [
                    'workflowRunIds' => [11, 22],
                    'stepNames' => ['fetch_company', 'write_company'],
                ],
                [
                    'workflowRunIds' => ArrayParameterType::INTEGER,
                    'stepNames' => ArrayParameterType::STRING,
                ],
            )
            ->willReturn([
                [
                    'workflow_run_id' => 11,
                    'step_name' => 'fetch_company',
                    'step_type' => 'fetch',
                    'status' => 'running',
                    'processed_count' => 14,
                    'success_count' => 13,
                    'error_count' => 1,
                    'duration_ms' => 321,
                    'memory_peak_bytes' => 1048576,
                    'idempotence_key' => 'idem-1',
                    'deduplication_status' => 'applied',
                    'error_message' => null,
                    'metadata' => '{"error":{"category":"transient"}}',
                    'deduplicated_from_run_id' => null,
                ],
                [
                    'workflow_run_id' => 22,
                    'step_name' => 'write_company',
                    'step_type' => 'write',
                    'status' => 'failed',
                    'processed_count' => 4,
                    'success_count' => 0,
                    'error_count' => 4,
                    'duration_ms' => null,
                    'memory_peak_bytes' => null,
                    'idempotence_key' => 'idem-2',
                    'deduplication_status' => 'deduplicated',
                    'error_message' => 'boom',
                    'metadata' => '{"error":{"category":"fatal"}}',
                    'deduplicated_from_run_id' => 'run-source',
                ],
            ]);

        $statistics = $this->createStatistics($connection);

        $runOne = $this->createWorkflowRunWithDatabaseId(11, 'run-1');
        $runTwo = $this->createWorkflowRunWithDatabaseId(22, 'run-2');

        self::assertSame(
            [
                'run-1::fetch_company' => [
                    'stepType' => 'fetch',
                    'status' => 'running',
                    'processedCount' => 14,
                    'successCount' => 13,
                    'errorCount' => 1,
                    'durationMs' => 321,
                    'memoryPeakBytes' => 1048576,
                    'idempotenceKey' => 'idem-1',
                    'deduplicationStatus' => 'applied',
                    'deduplicatedFromRunId' => null,
                    'errorMessage' => null,
                    'errorPayload' => ['category' => 'transient'],
                ],
                'run-2::write_company' => [
                    'stepType' => 'write',
                    'status' => 'failed',
                    'processedCount' => 4,
                    'successCount' => 0,
                    'errorCount' => 4,
                    'durationMs' => null,
                    'memoryPeakBytes' => null,
                    'idempotenceKey' => 'idem-2',
                    'deduplicationStatus' => 'deduplicated',
                    'deduplicatedFromRunId' => 'run-source',
                    'errorMessage' => 'boom',
                    'errorPayload' => ['category' => 'fatal'],
                ],
            ],
            $statistics->findLatestByWorkflowRunsAndStepNamesIndexed(
                [$runOne, $runTwo],
                ['fetch_company', 'write_company', 'fetch_company'],
            ),
        );
    }

    #[Test]
    public function it_summarizes_step_metrics_for_multiple_runs_without_hydration(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->with(
                self::stringContains('SUM(workflow_step_run.processed_count) AS processed_total'),
                [
                    'workflowRunIds' => [11, 22],
                ],
                [
                    'workflowRunIds' => ArrayParameterType::INTEGER,
                ],
            )
            ->willReturn([
                'processed_total' => 18,
                'success_total' => 14,
                'error_total' => 4,
                'duration_total' => 750,
                'duration_count' => 3,
                'max_duration_ms' => 420,
            ]);

        $statistics = $this->createStatistics($connection);

        self::assertSame(
            [
                'processedTotal' => 18,
                'successTotal' => 14,
                'errorTotal' => 4,
                'durationTotal' => 750,
                'durationCount' => 3,
                'maxDurationMs' => 420,
            ],
            $statistics->summarizeByWorkflowRuns([
                $this->createWorkflowRunWithDatabaseId(11, 'run-1'),
                $this->createWorkflowRunWithDatabaseId(22, 'run-2'),
            ]),
        );
    }

    #[Test]
    public function it_fetches_only_errored_step_rows_for_requested_runs(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(
                self::stringContains('workflow_step_run.status IN (:statuses)'),
                [
                    'workflowRunIds' => [11, 22],
                    'statuses' => ['failed', 'retrying', 'cancelled'],
                ],
                [
                    'workflowRunIds' => ArrayParameterType::INTEGER,
                    'statuses' => ArrayParameterType::STRING,
                ],
            )
            ->willReturn([
                [
                    'workflow_run_id' => 11,
                    'step_name' => 'fetch_company',
                    'step_type' => 'fetch',
                    'status' => 'failed',
                    'processed_count' => 10,
                    'success_count' => 7,
                    'error_count' => 3,
                    'duration_ms' => 420,
                    'memory_peak_bytes' => 2048,
                    'retry_count' => 2,
                    'started_at' => '2026-07-22 10:00:00',
                    'finished_at' => '2026-07-22 10:01:00',
                    'error_message' => 'boom',
                    'metadata' => '{"error":{"category":"fatal","code":"E42"}}',
                ],
                [
                    'workflow_run_id' => 22,
                    'step_name' => 'write_company',
                    'step_type' => 'write',
                    'status' => 'retrying',
                    'processed_count' => 4,
                    'success_count' => 0,
                    'error_count' => 4,
                    'duration_ms' => null,
                    'memory_peak_bytes' => null,
                    'retry_count' => 1,
                    'started_at' => null,
                    'finished_at' => null,
                    'error_message' => 'retry later',
                    'metadata' => '{"error":{"category":"transient"}}',
                ],
            ]);

        $statistics = $this->createStatistics($connection);

        self::assertEquals(
            [
                'run-1' => [[
                    'code' => 'fetch_company',
                    'type' => 'fetch',
                    'status' => 'failed',
                    'processed' => 10,
                    'success' => 7,
                    'errors' => 3,
                    'durationMs' => 420,
                    'memoryPeakBytes' => 2048,
                    'retries' => 2,
                    'startedAt' => new DateTimeImmutable('2026-07-22 10:00:00'),
                    'finishedAt' => new DateTimeImmutable('2026-07-22 10:01:00'),
                    'error' => 'boom',
                    'errorDetails' => ['category: fatal', 'code: E42'],
                ]],
                'run-2' => [[
                    'code' => 'write_company',
                    'type' => 'write',
                    'status' => 'retrying',
                    'processed' => 4,
                    'success' => 0,
                    'errors' => 4,
                    'durationMs' => null,
                    'memoryPeakBytes' => null,
                    'retries' => 1,
                    'startedAt' => null,
                    'finishedAt' => null,
                    'error' => 'retry later',
                    'errorDetails' => ['category: transient'],
                ]],
            ],
            $statistics->findErroredStepRowsByWorkflowRunsGrouped([
                $this->createWorkflowRunWithDatabaseId(11, 'run-1'),
                $this->createWorkflowRunWithDatabaseId(22, 'run-2'),
            ]),
        );
    }

    #[Test]
    public function it_aggregates_latest_step_statistics_without_hydrating_step_runs(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(
                self::stringContains('GROUP BY workflow_step_run.step_name'),
                [
                    'workflowName' => 'workflow',
                    'startAt' => new DateTimeImmutable('2026-07-01 00:00:00'),
                    'failedStatus' => 'failed',
                    'noDeduplicationStatus' => 'none',
                ],
                [
                    'startAt' => Types::DATETIME_IMMUTABLE,
                ],
            )
            ->willReturn([
                [
                    'step_name' => 'fetch_company',
                    'duration_total' => 230,
                    'duration_count' => 2,
                    'failure_count' => 1,
                    'retry_count' => 3,
                    'idempotence_hit_count' => 1,
                    'execution_count' => 4,
                    'processed_total' => 10,
                    'success_total' => 9,
                    'record_error_total' => 1,
                ],
            ]);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->with(
                self::stringContains('COUNT(DISTINCT workflow_step_run.workflow_run_id)'),
                [
                    'workflowName' => 'workflow',
                    'startAt' => new DateTimeImmutable('2026-07-01 00:00:00'),
                ],
                [
                    'startAt' => Types::DATETIME_IMMUTABLE,
                ],
            )
            ->willReturn(2);

        $statistics = $this->createStatistics($connection);

        self::assertSame(
            [
                'retryRunCount' => 2,
                'processedTotal' => 10,
                'successTotal' => 9,
                'recordErrorTotal' => 1,
                'steps' => [
                    'fetch_company' => [
                        'durationTotal' => 230,
                        'durationCount' => 2,
                        'failureCount' => 1,
                        'retryCount' => 3,
                        'idempotenceHitCount' => 1,
                        'executionCount' => 4,
                    ],
                ],
            ],
            $statistics->aggregateLatestStepStatisticsByWorkflowNameSince(
                'workflow',
                new DateTimeImmutable('2026-07-01 00:00:00'),
            ),
        );
    }

    private function createWorkflowRunWithDatabaseId(int $databaseId, string $runId): WorkflowRun
    {
        $workflowRun = new WorkflowRun(
            runId: $runId,
            workflowName: 'workflow',
            sourceSystem: 'source',
            targetSystem: 'target',
            trigger: 'runtime',
        );

        $idProperty = new ReflectionProperty(WorkflowRun::class, 'id');
        $idProperty->setValue($workflowRun, $databaseId);

        return $workflowRun;
    }

    private function createStatistics(Connection $connection): WorkflowStepRunStatistics
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($entityManager);

        return new WorkflowStepRunStatistics($registry);
    }
}
