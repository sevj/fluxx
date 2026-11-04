<?php

declare(strict_types=1);

namespace Fluxx\Tests\Workflow\Lock;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Fluxx\Entity\Enum\WorkflowExecutionLockScope;
use Fluxx\Entity\WorkflowExecutionLock;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Repository\FluxxSettingLookupInterface;
use Fluxx\Repository\RuntimeWorkerStateLookupInterface;
use Fluxx\Repository\WorkflowExecutionLockStoreInterface;
use Fluxx\Repository\WorkflowRunLookupInterface;
use Fluxx\Repository\WorkflowStepRunLookupInterface;
use Fluxx\Settings\RuntimeSettingsManager;
use Fluxx\Workflow\Lock\WorkflowExecutionLockConfiguration;
use Fluxx\Workflow\Lock\WorkflowExecutionLockConflict;
use Fluxx\Workflow\Lock\WorkflowExecutionLockManager;
use Fluxx\Workflow\Lock\WorkflowRunAbandonmentDetector;
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
            workflowRunRepository: $this->createStub(WorkflowRunLookupInterface::class),
            abandonmentDetector: $this->detector(activeWorker: false),
            runtimeSettingsManager: $this->runtimeSettingsManager(),
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

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist');

        $manager = new WorkflowExecutionLockManager(
            entityManager: $entityManager,
            workflowExecutionLockRepository: $lockRepository,
            workflowRunRepository: $runRepository,
            abandonmentDetector: $this->detector(activeWorker: true),
            runtimeSettingsManager: $this->runtimeSettingsManager(),
        );

        $manager->acquire($this->workflowRun('run-new'), $this->definition(WorkflowExecutionLockScope::WorkflowSource));

        self::assertFalse($existingLock->isActive());
        self::assertSame('stale_recovered', $existingLock->releaseReason());
    }

    #[Test]
    public function it_throws_when_a_live_run_has_no_worker_between_two_steps(): void
    {
        $manager = $this->managerWithOwner(new DateTimeImmutable('-1 minute'), activeWorker: false);

        $this->expectException(WorkflowExecutionLockConflict::class);

        $manager->acquire($this->workflowRun('run-new'), $this->definition(WorkflowExecutionLockScope::WorkflowSource));
    }

    #[Test]
    public function it_throws_when_a_worker_is_processing_an_inactive_run(): void
    {
        $manager = $this->managerWithOwner(new DateTimeImmutable('-1 hour'), activeWorker: true);

        $this->expectException(WorkflowExecutionLockConflict::class);

        $manager->acquire($this->workflowRun('run-new'), $this->definition(WorkflowExecutionLockScope::WorkflowSource));
    }

    #[Test]
    public function it_frees_an_abandoned_lock_before_persisting_the_new_one(): void
    {
        $existingLock = new WorkflowExecutionLock('contacts', 'run-old', 'contacts:CSV', WorkflowExecutionLockScope::WorkflowSource);
        $calls = [];
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('flush')->willReturnCallback(function () use (&$calls, $existingLock): void {
            $calls[] = $existingLock->isActive() ? 'flush_active' : 'flush_released';
        });
        $entityManager->method('persist')->willReturnCallback(function () use (&$calls): void {
            $calls[] = 'persist';
        });

        $manager = $this->managerWithOwner(new DateTimeImmutable('-1 hour'), activeWorker: false, existingLock: $existingLock, entityManager: $entityManager);
        $manager->acquire($this->workflowRun('run-new'), $this->definition(WorkflowExecutionLockScope::WorkflowSource));

        self::assertSame('stale_recovered', $existingLock->releaseReason());
        self::assertSame(['flush_released', 'persist'], $calls);
    }

    private function managerWithOwner(
        DateTimeImmutable $startedAt,
        bool $activeWorker,
        ?WorkflowExecutionLock $existingLock = null,
        ?EntityManagerInterface $entityManager = null,
    ): WorkflowExecutionLockManager {
        $ownerRun = $this->workflowRun('run-old');
        $ownerRun->markRunning($startedAt);

        $lockRepository = $this->createStub(WorkflowExecutionLockStoreInterface::class);
        $lockRepository->method('findActiveByLockKey')->willReturn(
            $existingLock ?? new WorkflowExecutionLock('contacts', 'run-old', 'contacts:CSV', WorkflowExecutionLockScope::WorkflowSource),
        );

        $runRepository = $this->createStub(WorkflowRunLookupInterface::class);
        $runRepository->method('findOneByRunId')->willReturn($ownerRun);

        return new WorkflowExecutionLockManager(
            entityManager: $entityManager ?? $this->createStub(EntityManagerInterface::class),
            workflowExecutionLockRepository: $lockRepository,
            workflowRunRepository: $runRepository,
            abandonmentDetector: $this->detector($activeWorker),
            runtimeSettingsManager: $this->runtimeSettingsManager(),
        );
    }

    private function detector(bool $activeWorker): WorkflowRunAbandonmentDetector
    {
        $stepRunRepository = $this->createStub(WorkflowStepRunLookupInterface::class);
        $stepRunRepository->method('findByWorkflowRunOrdered')->willReturn([]);
        $workerRepository = $this->createStub(RuntimeWorkerStateLookupInterface::class);
        $workerRepository->method('hasActiveWorkerForRun')->willReturn($activeWorker);

        return new WorkflowRunAbandonmentDetector($stepRunRepository, $workerRepository);
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

    private function runtimeSettingsManager(): RuntimeSettingsManager
    {
        $settingRepository = $this->createStub(FluxxSettingLookupInterface::class);
        $settingRepository->method('findValue')->willReturn(null);

        return new RuntimeSettingsManager(
            settingRepository: $settingRepository,
            defaultStaleLockTimeoutSeconds: 1800,
            defaultWorkerHeartbeatTimeoutSeconds: 120,
            defaultHealthWarningThresholdSeconds: 60,
            defaultHealthCriticalThresholdSeconds: 300,
            defaultMaxGlobalRetries: 10,
        );
    }
}
