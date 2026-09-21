<?php

declare(strict_types=1);

namespace Fluxx\Tests\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use Fluxx\Entity\WorkflowStepRun;
use Fluxx\Repository\WorkflowStepRunRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkflowStepRunRepositoryTest extends TestCase
{
    #[Test]
    public function it_returns_paginated_troubleshooting_issue_rows_without_full_entity_hydration(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(
                self::stringContains('LIMIT :limit OFFSET :offset'),
                [
                    'workflowNames' => ['workflow'],
                    'stepStatus' => 'failed',
                    'runStatuses' => ['failed', 'partially_failed'],
                    'search' => '%display%',
                    'matchedStepWorkflowCode0' => 'workflow',
                    'matchedStepCodes0' => ['fetch_company'],
                    'limit' => 10,
                    'offset' => 20,
                ],
                [
                    'workflowNames' => ArrayParameterType::STRING,
                    'runStatuses' => ArrayParameterType::STRING,
                    'matchedStepCodes0' => ArrayParameterType::STRING,
                    'limit' => Types::INTEGER,
                    'offset' => Types::INTEGER,
                ],
            )
            ->willReturn([
                [
                    'workflow_code' => 'workflow',
                    'source_system' => 'source',
                    'target_system' => 'target',
                    'run_id' => 'run-1',
                    'step_name' => 'fetch_company',
                    'failed_at' => '2026-07-22 12:00:00',
                    'error_message' => 'boom',
                    'failure_count' => 3,
                ],
            ]);

        $repository = $this->createRepository($connection);

        self::assertEquals(
            [
                [
                    'workflowCode' => 'workflow',
                    'sourceSystem' => 'source',
                    'targetSystem' => 'target',
                    'runId' => 'run-1',
                    'stepCode' => 'fetch_company',
                    'failedAt' => new DateTimeImmutable('2026-07-22 12:00:00'),
                    'errorMessage' => 'boom',
                    'failureCount' => 3,
                ],
            ],
            $repository->findTroubleshootingIssueRowsByWorkflowNames(
                ['workflow'],
                10,
                20,
                'display',
                [],
                ['workflow' => ['fetch_company']],
            ),
        );
    }

    private function createRepository(Connection $connection): WorkflowStepRunRepository
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getClassMetadata')->willReturn(new ClassMetadata(WorkflowStepRun::class));
        $entityManager->method('getConnection')->willReturn($connection);

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($entityManager);

        return new WorkflowStepRunRepository($registry);
    }
}
