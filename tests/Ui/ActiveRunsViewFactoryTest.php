<?php

declare(strict_types=1);

namespace Fluxx\Tests\Ui;

use DateTimeImmutable;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;
use Fluxx\StepType\StepTypeRegistry;
use Fluxx\Tests\Fixture\FixtureWorkflow;
use Fluxx\Tests\Fixture\StubExecutableStep;
use Fluxx\Ui\ActiveRunsViewFactory;
use Fluxx\Workflow\SynchronizationRegistry;
use Fluxx\Workflow\WorkflowDefinition;
use Fluxx\Workflow\WorkflowStepDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ActiveRunsViewFactoryTest extends TestCase
{
    private const NOW = '2026-10-15 09:00:00';

    #[Test]
    public function it_reports_progress_and_current_steps_of_a_live_run(): void
    {
        $start = new DateTimeImmutable(self::NOW.' - 2 minutes');
        $now = new DateTimeImmutable(self::NOW);
        $run = $this->createRun(startedAt: $start);
        $read = $this->createStepRun($run, 'read', 0);
        $read->markRunning($start);
        $read->markCompleted(processedCount: 1, successCount: 1, errorCount: 0, durationMs: 1000, finishedAt: $start->modify('+1 second'));
        $write = $this->createStepRun($run, 'write', 1);
        $write->markRunning($start->modify('+1 second'));

        $row = $this->factory()->row(run: $run, stepRuns: [$read, $write], workerNames: ['worker-1'], hasLiveWorker: true, staleSeconds: 1800, now: $now);

        self::assertSame(1, $row->completedSteps());
        self::assertSame(3, $row->totalSteps());
        self::assertEqualsWithDelta(33.3, $row->progressPercent(), 0.1);
        self::assertSame(1, count($row->currentSteps()));
        self::assertSame('Write', $row->currentSteps()[0]->name());
        self::assertSame('running', $row->currentSteps()[0]->status());
        self::assertNotNull($row->currentSteps()[0]->elapsedLabel());
        self::assertSame(['worker-1'], $row->workerNames());
        self::assertSame('running', $row->status());
    }

    #[Test]
    public function it_counts_failed_steps_without_marking_them_as_running(): void
    {
        $start = new DateTimeImmutable(self::NOW.' - 5 minutes');
        $now = new DateTimeImmutable(self::NOW);
        $run = $this->createRun(startedAt: $start);
        $read = $this->createStepRun($run, 'read', 0);
        $read->markRunning($start);
        $read->markCompleted(processedCount: 1, successCount: 1, errorCount: 0, durationMs: 1000, finishedAt: $start->modify('+1 second'));
        $write = $this->createStepRun($run, 'write', 1);
        $write->markRunning($start->modify('+1 second'));
        $write->markFailed('Writing to target failed.');

        $row = $this->factory()->row(run: $run, stepRuns: [$read, $write], workerNames: [], hasLiveWorker: false, staleSeconds: 1800, now: $now);

        self::assertSame(1, $row->failedSteps());
        self::assertSame(0, count($row->currentSteps()));
        self::assertSame(3, $row->totalSteps());
        self::assertEqualsWithDelta(33.3, $row->progressPercent(), 0.1);
    }

    #[Test]
    public function it_falls_back_to_recorded_step_count_when_workflow_is_unknown(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $run = $this->createRun('unknown_workflow');
        $read = $this->createStepRun($run, 'read', 0);
        $read->markCompleted(processedCount: 1, successCount: 1, errorCount: 0, durationMs: 100);

        $row = $this->factory()->row(run: $run, stepRuns: [$read], workerNames: [], hasLiveWorker: false, staleSeconds: 1800, now: $now);

        self::assertSame(1, $row->totalSteps());
        self::assertNull($row->workflowName());
    }

    #[Test]
    public function it_keeps_activity_within_the_fifteen_minute_window(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $run = $this->createRun();
        $read = $this->createStepRun($run, 'read', 0);
        $read->markRunning($now->modify('-18 minutes'));
        $read->markCompleted(processedCount: 1, successCount: 1, errorCount: 0, durationMs: 100, finishedAt: $now->modify('-18 minutes'));

        self::assertNull($this->factory()->activity($read, $now));

        $write = $this->createStepRun($run, 'write', 1);
        $write->markRunning($now->modify('-10 seconds'));

        $activity = $this->factory()->activity($write, $now);

        self::assertNotNull($activity);
        self::assertSame('running', $activity->kind());
        self::assertSame('Write', $activity->stepName());
        self::assertSame($run->runId(), $activity->runId());
        self::assertSame('contacts', $activity->workflowCode());
        self::assertNull($activity->errorMessage());
    }

    #[Test]
    public function it_maps_statuses_to_activity_kinds_and_exposes_errors(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $run = $this->createRun();
        $failed = $this->createStepRun($run, 'write', 0);
        $failed->markRunning($now->modify('-2 minutes'));
        $failed->markFailed('Connection refused.', finishedAt: $now->modify('-1 minute'));

        $activity = $this->factory()->activity($failed, $now);

        self::assertNotNull($activity);
        self::assertSame('failed', $activity->kind());
        self::assertSame('Connection refused.', $activity->errorMessage());
    }

    #[Test]
    public function it_maps_scheduled_retries_to_the_retrying_kind(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $run = $this->createRun();
        $write = $this->createStepRun($run, 'write', 0);
        $write->markRunning($now->modify('-3 minutes'));
        $write->scheduleRetry(
            $now->modify('-1 minute'),
            $now->modify('+3 minutes'),
            'Temporary failure.',
        );

        $activity = $this->factory()->activity($write, $now);

        self::assertNotNull($activity);
        self::assertSame('retrying', $activity->kind());
    }

    #[Test]
    public function it_flags_stale_runs_without_live_worker_as_orphaned(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $run = $this->createRun(startedAt: $now->modify('-45 minutes'));
        $read = $this->createStepRun($run, 'read', 0);
        $read->markRunning($now->modify('-45 minutes'));
        $read->markCompleted(processedCount: 1, successCount: 1, errorCount: 0, durationMs: 1000, finishedAt: $now->modify('-44 minutes'));

        $row = $this->factory()->row(run: $run, stepRuns: [$read], workerNames: [], hasLiveWorker: false, staleSeconds: 1800, now: $now);

        self::assertTrue($row->isOrphaned());
    }

    #[Test]
    public function it_does_not_flag_runs_with_recent_activity_or_live_worker(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $run = $this->createRun(startedAt: $now->modify('-45 minutes'));
        $read = $this->createStepRun($run, 'read', 0);
        $read->markRunning($now->modify('-45 minutes'));
        $read->markCompleted(processedCount: 1, successCount: 1, errorCount: 0, durationMs: 1000, finishedAt: $now->modify('-44 minutes'));

        self::assertFalse($this->factory()->row(run: $run, stepRuns: [$read], workerNames: [], hasLiveWorker: true, staleSeconds: 1800, now: $now)->isOrphaned());

        $freshRun = $this->createRun(startedAt: $now->modify('-2 minutes'));
        self::assertFalse($this->factory()->row(run: $freshRun, stepRuns: [], workerNames: [], hasLiveWorker: false, staleSeconds: 1800, now: $now)->isOrphaned());
    }

    #[Test]
    public function it_never_flags_synchronous_runs_as_orphaned(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $run = $this->createRun(startedAt: $now->modify('-2 hours'));
        $run->markSynchronous();

        $row = $this->factory()->row(run: $run, stepRuns: [], workerNames: [], hasLiveWorker: false, staleSeconds: 1800, now: $now);

        self::assertFalse($row->isOrphaned());
    }

    private function factory(): ActiveRunsViewFactory
    {
        return new ActiveRunsViewFactory(
            new SynchronizationRegistry([new FixtureWorkflow($this->definition())]),
            new StepTypeRegistry([]),
        );
    }

    private function definition(): WorkflowDefinition
    {
        return new WorkflowDefinition(
            code: 'contacts',
            name: 'Contacts',
            sourceSystem: 'CSV',
            targetSystem: 'Hubspot',
            steps: [
                new WorkflowStepDefinition('read', 'Read', 'read', new StubExecutableStep('read', 'Read')),
                new WorkflowStepDefinition('transform', 'Transform', 'transform', new StubExecutableStep('transform', 'Transform'), ['read']),
                new WorkflowStepDefinition('write', 'Write', 'write', new StubExecutableStep('write', 'Write'), ['transform']),
            ],
        );
    }

    private function createRun(string $workflowName = 'contacts', ?DateTimeImmutable $startedAt = null): WorkflowRun
    {
        $run = new WorkflowRun(
            runId: 'run-'.uniqid('', true),
            workflowName: $workflowName,
            sourceSystem: 'CSV',
            targetSystem: 'Hubspot',
            trigger: 'manual',
        );
        $run->markRunning($startedAt ?? new DateTimeImmutable());

        return $run;
    }

    private function createStepRun(WorkflowRun $run, string $code, int $position): WorkflowStepRun
    {
        return new WorkflowStepRun(
            workflowRun: $run,
            stepType: $code,
            stepName: $code,
            position: $position,
        );
    }
}
