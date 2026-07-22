<?php

declare(strict_types=1);

namespace Fluxx\Tests\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Repository\WorkflowRunRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkflowRunRepositoryTest extends TestCase
{
    #[Test]
    public function it_aggregates_bucket_counts_without_loading_run_entities(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($this->createMock(AbstractMySQLPlatform::class));
        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(
                self::stringContains("DATE_FORMAT(created_at, '%Y-%m-%d')"),
                [
                    'workflowName' => 'workflow',
                    'startAt' => new DateTimeImmutable('2026-07-01 00:00:00'),
                    'errorStatuses' => ['failed', 'partially_failed'],
                ],
                [
                    'startAt' => Types::DATETIME_IMMUTABLE,
                    'errorStatuses' => ArrayParameterType::STRING,
                ],
            )
            ->willReturn([
                ['bucket_key' => '2026-07-21', 'execution_count' => 12, 'error_count' => 2],
                ['bucket_key' => '2026-07-22', 'execution_count' => 7, 'error_count' => 1],
            ]);

        $repository = $this->createRepository($connection);

        self::assertSame(
            [
                '2026-07-21' => ['executionCount' => 12, 'errorCount' => 2],
                '2026-07-22' => ['executionCount' => 7, 'errorCount' => 1],
            ],
            $repository->aggregateCreatedSinceByWorkflowName(
                'workflow',
                new DateTimeImmutable('2026-07-01 00:00:00'),
                'day',
            ),
        );
    }

    #[Test]
    public function it_summarizes_statistics_with_scalar_queries_only(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($this->createMock(AbstractMySQLPlatform::class));
        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->with(
                self::stringContains('SUM(CASE WHEN CAST(metadata AS CHAR) LIKE :relaunchMarker'),
                [
                    'workflowName' => 'workflow',
                    'startAt' => new DateTimeImmutable('2026-07-01 00:00:00'),
                    'failedStatus' => 'failed',
                    'partialFailedStatus' => 'partially_failed',
                    'relaunchMarker' => '%"relaunch":%',
                ],
                [
                    'startAt' => Types::DATETIME_IMMUTABLE,
                ],
            )
            ->willReturn([
                'run_count' => 14,
                'failed_count' => 3,
                'partial_failed_count' => 2,
                'relaunch_count' => 4,
            ]);
        $connection->expects(self::once())
            ->method('fetchFirstColumn')
            ->with(
                self::stringContains('TIMESTAMPDIFF(MICROSECOND, started_at, finished_at) DIV 1000'),
                [
                    'workflowName' => 'workflow',
                    'startAt' => new DateTimeImmutable('2026-07-01 00:00:00'),
                ],
                [
                    'startAt' => Types::DATETIME_IMMUTABLE,
                ],
            )
            ->willReturn([120, '340', 0]);

        $repository = $this->createRepository($connection);

        self::assertSame(
            [
                'runCount' => 14,
                'failedCount' => 3,
                'partialFailedCount' => 2,
                'relaunchCount' => 4,
                'durations' => [120, 340, 0],
            ],
            $repository->summarizeCreatedSinceByWorkflowName(
                'workflow',
                new DateTimeImmutable('2026-07-01 00:00:00'),
            ),
        );
    }

    #[Test]
    public function it_summarizes_multiple_workflows_in_a_single_grouped_query(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(
                self::stringContains('GROUP BY workflow_name'),
                [
                    'workflowNames' => ['alpha', 'bravo'],
                    'errorStatuses' => ['failed', 'partially_failed'],
                ],
                [
                    'workflowNames' => ArrayParameterType::STRING,
                    'errorStatuses' => ArrayParameterType::STRING,
                ],
            )
            ->willReturn([
                [
                    'workflow_name' => 'alpha',
                    'execution_count' => 12,
                    'error_count' => 2,
                    'last_execution_at' => '2026-07-22 10:00:00',
                    'last_error_at' => '2026-07-22 09:00:00',
                ],
                [
                    'workflow_name' => 'bravo',
                    'execution_count' => 3,
                    'error_count' => 0,
                    'last_execution_at' => '2026-07-21 08:30:00',
                    'last_error_at' => null,
                ],
            ]);

        $repository = $this->createRepository($connection);

        self::assertSame(
            [
                'alpha' => [
                    'executionCount' => 12,
                    'errorCount' => 2,
                    'lastExecutionAt' => new DateTimeImmutable('2026-07-22 10:00:00'),
                    'lastErrorAt' => new DateTimeImmutable('2026-07-22 09:00:00'),
                ],
                'bravo' => [
                    'executionCount' => 3,
                    'errorCount' => 0,
                    'lastExecutionAt' => new DateTimeImmutable('2026-07-21 08:30:00'),
                    'lastErrorAt' => null,
                ],
            ],
            $repository->summarizeByWorkflowNames(['alpha', 'bravo', 'alpha']),
        );
    }

    private function createRepository(Connection $connection): WorkflowRunRepository
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getClassMetadata')
            ->with(WorkflowRun::class)
            ->willReturn(new ClassMetadata(WorkflowRun::class));
        $entityManager->method('getConnection')->willReturn($connection);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')
            ->with(WorkflowRun::class)
            ->willReturn($entityManager);

        return new WorkflowRunRepository($registry);
    }
}
