<?php

declare(strict_types=1);

namespace Fluxx\Tests\Workflow\Lock;

use Doctrine\ORM\EntityManagerInterface;
use Fluxx\Entity\Enum\WorkflowExecutionLockScope;
use Fluxx\Entity\WorkflowExecutionLock;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Repository\RuntimeWorkerStateLookupInterface;
use Fluxx\Repository\WorkflowExecutionLockStoreInterface;
use Fluxx\Repository\WorkflowRunLookupInterface;
use Fluxx\Workflow\Lock\WorkflowExecutionLockConfiguration;
use Fluxx\Workflow\Lock\WorkflowExecutionLockConflict;
use Fluxx\Workflow\Lock\WorkflowExecutionLockManager;
use Fluxx\Workflow\WorkflowDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkflowExecutionLockManagerTest extends TestCase
{
    #[Test]
    public function it_builds_and_persists_a_source_target_lock(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::callback(static fn ($lock): bool => $lock instanceof WorkflowExecutionLock
                && $lock->lockKey() === 'contacts:CSV:Hubspot'
                && $lock->scope() === WorkflowExecutionLockScope::WorkflowSourceTarget));

        $lockRepository = $this->createMock(WorkflowExecutionLockStoreInterface::class);
        $lockRepository->expects(self::once())
            ->method('findActiveByLockKey')
            ->with('contacts:CSV:Hubspot')
            ->willReturn(null);

        $manager = new WorkflowExecutionLockManager(
            entityManager: $entityManager,
            workflowExecutionLockRepository: $lockRepository,
            workflowRunRepository: $this->createMock(WorkflowRunLookupInterface::class),
            runtimeWorkerStateRepository: $this->createMock(RuntimeWorkerStateLookupInterface::class),
        );

        $run = $this->workflowRun('run-1');
        $lock = $manager->acquire($run, $this->definition(WorkflowExecutionLockScope::WorkflowSourceTarget));

        self::assertInstanceOf(WorkflowExecutionLock::class, $lock);
        self::assertSame('contacts:CSV:Hubspot', $run->lockKey());
        self::assertSame(WorkflowExecutionLockScope::WorkflowSourceTarget, $run->lockScope());
    }

    #[Test]
    public function it_recovers_a_stale_lock_when_the_owner_run_is_terminal(): void
    {
        $existingLock = new WorkflowExecutionLock('contacts', 'run-old', 'contacts:CSV', WorkflowExecutionLockScope::WorkflowSource);
        $ownerRun = $this->workflowRun('run-old');
        $ownerRun->markCompleted();

        $lockRepository = $this->createMock(WorkflowExecutionLockStoreInterface::class);
        $lockRepository->expects(self::once())
            ->method('findActiveByLockKey')
            ->with('contacts:CSV')
            ->willReturn($existingLock);

        $runRepository = $this->createMock(WorkflowRunLookupInterface::class);
        $runRepository->expects(self::once())
            ->method('findOneByRunId')
            ->with('run-old')
            ->willReturn($ownerRun);

        $runtimeWorkerStateRepository = $this->createMock(RuntimeWorkerStateLookupInterface::class);
        $runtimeWorkerStateRepository->expects(self::never())->method('hasActiveWorkerForRun');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist');

        $manager = new WorkflowExecutionLockManager(
            entityManager: $entityManager,
            workflowExecutionLockRepository: $lockRepository,
            workflowRunRepository: $runRepository,
            runtimeWorkerStateRepository: $runtimeWorkerStateRepository,
        );

        $manager->acquire($this->workflowRun('run-new'), $this->definition(WorkflowExecutionLockScope::WorkflowSource));

        self::assertFalse($existingLock->isActive());
        self::assertSame('stale_recovered', $existingLock->releaseReason());
    }

    #[Test]
    public function it_throws_when_an_active_lock_is_still_owned_by_a_live_run(): void
    {
        $existingLock = new WorkflowExecutionLock('contacts', 'run-old', 'contacts:CSV', WorkflowExecutionLockScope::WorkflowSource);
        $ownerRun = $this->workflowRun('run-old');
        $ownerRun->markRunning();

        $lockRepository = $this->createMock(WorkflowExecutionLockStoreInterface::class);
        $lockRepository->method('findActiveByLockKey')->willReturn($existingLock);

        $runRepository = $this->createMock(WorkflowRunLookupInterface::class);
        $runRepository->method('findOneByRunId')->willReturn($ownerRun);

        $runtimeWorkerStateRepository = $this->createMock(RuntimeWorkerStateLookupInterface::class);
        $runtimeWorkerStateRepository->expects(self::once())
            ->method('hasActiveWorkerForRun')
            ->willReturn(true);

        $manager = new WorkflowExecutionLockManager(
            entityManager: $this->createMock(EntityManagerInterface::class),
            workflowExecutionLockRepository: $lockRepository,
            workflowRunRepository: $runRepository,
            runtimeWorkerStateRepository: $runtimeWorkerStateRepository,
        );

        $this->expectException(WorkflowExecutionLockConflict::class);

        $manager->acquire($this->workflowRun('run-new'), $this->definition(WorkflowExecutionLockScope::WorkflowSource));
    }

    private function workflowRun(string $runId): WorkflowRun
    {
        return new WorkflowRun(
            runId: $runId,
            workflowName: 'contacts',
            sourceSystem: 'CSV',
            targetSystem: 'Hubspot',
            trigger: 'manual',
        );
    }

    private function definition(WorkflowExecutionLockScope $scope): WorkflowDefinition
    {
        return new WorkflowDefinition(
            code: 'contacts',
            name: 'Contacts',
            sourceSystem: 'CSV',
            targetSystem: 'Hubspot',
            steps: [],
            lock: new WorkflowExecutionLockConfiguration($scope, staleTimeoutSeconds: 300),
        );
    }
}
