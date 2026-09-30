<?php

declare(strict_types=1);

namespace Fluxx\Tests\Ui;

use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;
use Fluxx\StepType\StepTypeRegistry;
use Fluxx\Tests\Fixture\FixtureWorkflow;
use Fluxx\Tests\Fixture\StubExecutableStep;
use Fluxx\Ui\RunTimeline;
use Fluxx\Workflow\SynchronizationRegistry;
use Fluxx\Workflow\WorkflowDefinition;
use Fluxx\Workflow\WorkflowStepDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RunTimelineTest extends TestCase
{
    #[Test]
    public function it_is_empty_when_no_step_has_started(): void
    {
        $timeline = $this->timeline($this->linearDefinition());
        $run = $this->createRun();
        $read = $this->createStepRun($run, 'read', 0);

        $view = $timeline->for($run, [$read]);

        self::assertFalse($view->hasTiming());
        self::assertCount(1, $view->steps());
        self::assertTrue($view->steps()[0]->isPending());
        self::assertNull($view->steps()[0]->leftPercent());
        self::assertNull($view->steps()[0]->widthPercent());
    }

    #[Test]
    public function it_lays_sequential_steps_end_to_end_from_duration_ms(): void
    {
        $timeline = $this->timeline($this->linearDefinition());
        $start = new \DateTimeImmutable('2026-09-30 10:00:00');
        $run = $this->createRun(startedAt: $start);

        $read = $this->createStepRun($run, 'read', 0);
        $read->markRunning($start);
        $read->markCompleted(processedCount: 1, successCount: 1, durationMs: 1000, finishedAt: $start->modify('+1 second'));

        $write = $this->createStepRun($run, 'write', 1);
        $write->markRunning($start->modify('+1 second'));
        $write->markCompleted(processedCount: 1, successCount: 1, durationMs: 3000, finishedAt: $start->modify('+4 seconds'));
        $run->markCompleted($start->modify('+4 seconds'));

        $view = $timeline->for($run, [$read, $write]);

        self::assertTrue($view->hasTiming());
        self::assertFalse($view->isLive());

        $readView = $view->steps()[0];
        $writeView = $view->steps()[1];

        self::assertSame(0.0, $readView->leftPercent());
        self::assertSame(25.0, $readView->widthPercent());

        self::assertSame(25.0, $writeView->leftPercent());
        self::assertSame(75.0, $writeView->widthPercent());

        self::assertSame(4000, $view->totalDurationMs());
    }

    #[Test]
    public function it_aligns_parallel_branches_and_chains_after_their_join(): void
    {
        $definition = $this->fanOutDefinition();
        $timeline = $this->timeline($definition);
        $start = new \DateTimeImmutable('2026-09-30 10:00:00');
        $run = $this->createRun($definition->code(), $start);

        $read = $this->createStepRun($run, 'read', 0);
        $read->markRunning($start);
        $read->markCompleted(processedCount: 1, successCount: 1, durationMs: 1000, finishedAt: $start->modify('+1 second'));

        $branchA = $this->createStepRun($run, 'branch_a', 1);
        $branchA->markRunning($start->modify('+1 second'));
        $branchA->markCompleted(processedCount: 1, successCount: 1, durationMs: 2000, finishedAt: $start->modify('+3 seconds'));

        $branchB = $this->createStepRun($run, 'branch_b', 2);
        $branchB->markRunning($start->modify('+1 second'));
        $branchB->markCompleted(processedCount: 1, successCount: 1, durationMs: 4000, finishedAt: $start->modify('+5 seconds'));

        $join = $this->createStepRun($run, 'join', 3);
        $join->markRunning($start->modify('+5 seconds'));
        $join->markCompleted(processedCount: 1, successCount: 1, durationMs: 1000, finishedAt: $start->modify('+6 seconds'));
        $run->markCompleted($start->modify('+6 seconds'));

        $view = $timeline->for($run, [$read, $branchA, $branchB, $join]);

        $byCode = [];
        foreach ($view->steps() as $stepView) {
            $byCode[$stepView->code()] = $stepView;
        }

        self::assertSame(0.0, $byCode['read']->leftPercent());
        self::assertEqualsWithDelta(1000 / 6000 * 100.0, $byCode['read']->widthPercent(), 0.001);

        self::assertEqualsWithDelta(1000 / 6000 * 100.0, $byCode['branch_a']->leftPercent(), 0.001);
        self::assertEqualsWithDelta(2000 / 6000 * 100.0, $byCode['branch_a']->widthPercent(), 0.001);

        self::assertEqualsWithDelta(1000 / 6000 * 100.0, $byCode['branch_b']->leftPercent(), 0.001);
        self::assertEqualsWithDelta(4000 / 6000 * 100.0, $byCode['branch_b']->widthPercent(), 0.001);

        self::assertEqualsWithDelta(5000 / 6000 * 100.0, $byCode['join']->leftPercent(), 0.001);
        self::assertEqualsWithDelta(1000 / 6000 * 100.0, $byCode['join']->widthPercent(), 0.001);

        self::assertSame(6000, $view->totalDurationMs());
    }

    #[Test]
    public function it_marks_an_in_flight_step_as_running_on_a_live_run(): void
    {
        $timeline = $this->timeline($this->linearDefinition());
        $start = new \DateTimeImmutable('-10 seconds');
        $run = $this->createRun(startedAt: $start);
        $write = $this->createStepRun($run, 'write', 0);
        $write->markRunning($start);

        $view = $timeline->for($run, [$write]);

        self::assertTrue($view->isLive());
        self::assertTrue($view->steps()[0]->isRunning());
        self::assertSame(0.0, $view->steps()[0]->leftPercent());
        self::assertGreaterThan(0.0, $view->steps()[0]->widthPercent());
    }

    #[Test]
    public function it_clamps_tiny_durations_to_a_visible_width(): void
    {
        $timeline = $this->timeline($this->linearDefinition());
        $start = new \DateTimeImmutable('2026-09-30 10:00:00');
        $run = $this->createRun(startedAt: $start);

        $quick = $this->createStepRun($run, 'read', 0);
        $quick->markRunning($start);
        $quick->markCompleted(processedCount: 1, successCount: 1, durationMs: 0, finishedAt: $start);

        $long = $this->createStepRun($run, 'write', 1);
        $long->markRunning($start->modify('+1 second'));
        $long->markCompleted(processedCount: 1, successCount: 1, durationMs: 10000, finishedAt: $start->modify('+11 seconds'));
        $run->markCompleted($start->modify('+11 seconds'));

        $view = $timeline->for($run, [$quick, $long]);

        self::assertSame(0.0, $view->steps()[0]->leftPercent());

        self::assertGreaterThanOrEqual(0.0, $view->steps()[0]->widthPercent());
    }

    #[Test]
    public function it_keeps_started_step_not_running_when_run_is_terminal(): void
    {
        $timeline = $this->timeline($this->linearDefinition());
        $start = new \DateTimeImmutable('2026-09-30 10:00:00');
        $run = $this->createRun(startedAt: $start);
        $read = $this->createStepRun($run, 'read', 0);
        $read->markRunning($start);
        $read->markFailed(errorMessage: 'boom', finishedAt: $start->modify('+1 second'));
        $run->markFailed(errorMessage: 'boom', finishedAt: $start->modify('+1 second'));

        $view = $timeline->for($run, [$read]);

        self::assertFalse($view->isLive());
        self::assertFalse($view->steps()[0]->isRunning());
    }

    private function timeline(WorkflowDefinition $definition): RunTimeline
    {
        return new RunTimeline(
            new SynchronizationRegistry([new FixtureWorkflow($definition)]),
            new StepTypeRegistry([]),
        );
    }

    private function linearDefinition(): WorkflowDefinition
    {
        return new WorkflowDefinition(
            code: 'contacts',
            name: 'Contacts',
            sourceSystem: 'CSV',
            targetSystem: 'Hubspot',
            steps: [
                new WorkflowStepDefinition('read', 'Read', 'read', new StubExecutableStep('read', 'Read')),
                new WorkflowStepDefinition('write', 'Write', 'write', new StubExecutableStep('write', 'Write'), ['read']),
            ],
        );
    }

    private function fanOutDefinition(): WorkflowDefinition
    {
        return new WorkflowDefinition(
            code: 'fan',
            name: 'Fan',
            sourceSystem: 'CSV',
            targetSystem: 'Hubspot',
            steps: [
                new WorkflowStepDefinition('read', 'Read', 'read', new StubExecutableStep('read', 'Read')),
                new WorkflowStepDefinition('branch_a', 'Branch A', 'transform', new StubExecutableStep('branch_a', 'Branch A'), ['read']),
                new WorkflowStepDefinition('branch_b', 'Branch B', 'transform', new StubExecutableStep('branch_b', 'Branch B'), ['read']),
                new WorkflowStepDefinition('join', 'Join', 'write', new StubExecutableStep('join', 'Join'), ['branch_a', 'branch_b']),
            ],
        );
    }

    private function createRun(string $workflowName = 'contacts', ?\DateTimeImmutable $startedAt = null): WorkflowRun
    {
        $run = new WorkflowRun(
            runId: 'run-' . uniqid('', true),
            workflowName: $workflowName,
            sourceSystem: 'CSV',
            targetSystem: 'Hubspot',
            trigger: 'manual',
        );
        $run->markRunning($startedAt ?? new \DateTimeImmutable());

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
