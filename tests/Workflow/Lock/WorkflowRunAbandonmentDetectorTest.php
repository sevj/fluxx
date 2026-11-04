<?php

declare(strict_types=1);

namespace Fluxx\Tests\Workflow\Lock;

use DateTimeImmutable;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;
use Fluxx\Repository\RuntimeWorkerStateLookupInterface;
use Fluxx\Repository\WorkflowStepRunLookupInterface;
use Fluxx\Workflow\Lock\WorkflowRunAbandonmentDetector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkflowRunAbandonmentDetectorTest extends TestCase
{
    #[Test]
    public function a_run_that_just_started_is_not_abandoned_even_without_a_worker(): void
    {
        self::assertFalse($this->isAbandoned($this->runStartedAt(new DateTimeImmutable('-1 minute')), [], activeWorker: false));
    }

    #[Test]
    public function a_run_whose_last_step_just_finished_is_not_abandoned(): void
    {
        $run = $this->runStartedAt(new DateTimeImmutable('-1 hour'));
        $step = new WorkflowStepRun($run, 'read', 'read', 1);
        $step->markRunning(new DateTimeImmutable('-30 minutes'));
        $step->markCompleted(processedCount: 1, successCount: 1, finishedAt: new DateTimeImmutable('-1 minute'));

        self::assertFalse($this->isAbandoned($run, [$step], activeWorker: false));
    }

    #[Test]
    public function a_run_waiting_for_a_scheduled_retry_is_not_abandoned(): void
    {
        $run = $this->runStartedAt(new DateTimeImmutable('-1 hour'));
        $step = new WorkflowStepRun($run, 'read', 'read', 1);
        $step->markRunning(new DateTimeImmutable('-1 hour'));
        $step->scheduleRetry(
            lastRetryAt: new DateTimeImmutable('-1 hour'),
            nextRetryAt: new DateTimeImmutable('+1 hour'),
            errorMessage: 'boom',
            errorCount: 1,
            durationMs: 1,
            memoryPeakBytes: 1,
        );

        self::assertFalse($this->isAbandoned($run, [$step], activeWorker: false));
    }

    #[Test]
    public function an_inactive_run_that_a_worker_is_processing_is_not_abandoned(): void
    {
        self::assertFalse($this->isAbandoned($this->runStartedAt(new DateTimeImmutable('-1 hour')), [], activeWorker: true));
    }

    #[Test]
    public function an_inactive_run_without_a_worker_is_abandoned(): void
    {
        self::assertTrue($this->isAbandoned($this->runStartedAt(new DateTimeImmutable('-1 hour')), [], activeWorker: false));
    }

    /**
     * @param list<WorkflowStepRun> $stepRuns
     */
    private function isAbandoned(WorkflowRun $run, array $stepRuns, bool $activeWorker): bool
    {
        $stepRunLookup = $this->createStub(WorkflowStepRunLookupInterface::class);
        $stepRunLookup->method('findByWorkflowRunOrdered')->willReturn($stepRuns);
        $workerLookup = $this->createStub(RuntimeWorkerStateLookupInterface::class);
        $workerLookup->method('hasActiveWorkerForRun')->willReturn($activeWorker);

        return (new WorkflowRunAbandonmentDetector($stepRunLookup, $workerLookup))
            ->isAbandoned($run, new DateTimeImmutable('-15 minutes'));
    }

    private function runStartedAt(DateTimeImmutable $startedAt): WorkflowRun
    {
        $run = new WorkflowRun(runId: 'run-1', workflowName: 'contacts', sourceSystem: 'CSV', targetSystem: 'Hubspot', trigger: 'manual');
        $run->markRunning($startedAt);

        return $run;
    }
}
