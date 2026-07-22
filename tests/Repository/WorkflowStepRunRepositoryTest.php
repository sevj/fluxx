<?php

declare(strict_types=1);

namespace Fluxx\Tests\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;
use Fluxx\Repository\WorkflowStepRunRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class WorkflowStepRunRepositoryTest extends TestCase
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
                    'duration_ms' => 321,
                    'memory_peak_bytes' => 1048576,
                    'error_message' => null,
                    'metadata' => '{"error":{"category":"transient"}}',
                ],
                [
                    'workflow_run_id' => 22,
                    'step_name' => 'write_company',
                    'step_type' => 'write',
                    'status' => 'failed',
                    'duration_ms' => null,
                    'memory_peak_bytes' => null,
                    'error_message' => 'boom',
                    'metadata' => '{"error":{"category":"fatal"}}',
                ],
            ]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getClassMetadata')
            ->with(WorkflowStepRun::class)
            ->willReturn(new ClassMetadata(WorkflowStepRun::class));
        $entityManager->expects(self::once())
            ->method('getConnection')
            ->willReturn($connection);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')
            ->with(WorkflowStepRun::class)
            ->willReturn($entityManager);

        $repository = new WorkflowStepRunRepository($registry);

        $runOne = $this->createWorkflowRunWithDatabaseId(11, 'run-1');
        $runTwo = $this->createWorkflowRunWithDatabaseId(22, 'run-2');

        self::assertSame(
            [
                'run-1::fetch_company' => [
                    'stepType' => 'fetch',
                    'status' => 'running',
                    'durationMs' => 321,
                    'memoryPeakBytes' => 1048576,
                    'errorMessage' => null,
                    'errorPayload' => ['category' => 'transient'],
                ],
                'run-2::write_company' => [
                    'stepType' => 'write',
                    'status' => 'failed',
                    'durationMs' => null,
                    'memoryPeakBytes' => null,
                    'errorMessage' => 'boom',
                    'errorPayload' => ['category' => 'fatal'],
                ],
            ],
            $repository->findLatestByWorkflowRunsAndStepNamesIndexed(
                [$runOne, $runTwo],
                ['fetch_company', 'write_company', 'fetch_company'],
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
}
