<?php

declare(strict_types=1);

namespace Fluxx\Tests\Operations;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Fluxx\Entity\Enum\WorkflowExecutionLockScope;
use Fluxx\Entity\WorkflowExecutionLock;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Operations\StaleLockReleaser;
use Fluxx\Repository\RuntimeWorkerStateLookupInterface;
use Fluxx\Repository\WorkflowExecutionLockStoreInterface;
use Fluxx\Repository\WorkflowRunLookupInterface;
use Fluxx\Repository\WorkflowStepRunLookupInterface;
use Fluxx\Workflow\Lock\WorkflowRunAbandonmentDetector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StaleLockReleaserTest extends TestCase
{
    #[Test]
    public function it_keeps_the_lock_of_a_live_run_waiting_for_a_worker(): void
    {
        $lock = $this->lock();

        self::assertSame([], $this->releaser($lock, new DateTimeImmutable('-1 minute'))->releaseStale(120));
        self::assertTrue($lock->isActive());
    }

    #[Test]
    public function it_releases_the_lock_of_an_abandoned_run(): void
    {
        $lock = $this->lock();

        $released = $this->releaser($lock, new DateTimeImmutable('-1 hour'))->releaseStale(120);

        self::assertSame('worker_heartbeat_stale', $released[0]['reason']);
        self::assertFalse($lock->isActive());
    }

    private function lock(): WorkflowExecutionLock
    {
        return new WorkflowExecutionLock('contacts', 'owner', 'contacts', WorkflowExecutionLockScope::Workflow);
    }

    private function releaser(WorkflowExecutionLock $lock, DateTimeImmutable $startedAt): StaleLockReleaser
    {
        $owner = new WorkflowRun(runId: 'owner', workflowName: 'contacts', sourceSystem: 'CSV', targetSystem: 'Hubspot', trigger: 'manual');
        $owner->markRunning($startedAt);

        $lockStore = $this->createStub(WorkflowExecutionLockStoreInterface::class);
        $lockStore->method('findActiveOrdered')->willReturn([$lock]);
        $runLookup = $this->createStub(WorkflowRunLookupInterface::class);
        $runLookup->method('findOneByRunId')->willReturn($owner);
        $stepRunLookup = $this->createStub(WorkflowStepRunLookupInterface::class);
        $stepRunLookup->method('findByWorkflowRunOrdered')->willReturn([]);
        $workerLookup = $this->createStub(RuntimeWorkerStateLookupInterface::class);
        $workerLookup->method('hasActiveWorkerForRun')->willReturn(false);

        return new StaleLockReleaser(
            $this->createStub(EntityManagerInterface::class),
            $lockStore,
            $runLookup,
            new WorkflowRunAbandonmentDetector($stepRunLookup, $workerLookup),
        );
    }
}
