<?php

declare(strict_types=1);

namespace Fluxx\Tests\Ui;

use DateTimeImmutable;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;
use Fluxx\StepType\StepTypeRegistry;
use Fluxx\Ui\RunTimeline;
use Fluxx\Workflow\SynchronizationRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RunTimelineTest extends TestCase
{
    private RunTimeline $timeline;

    protected function setUp(): void
    {
        $this->timeline = new RunTimeline(
            new SynchronizationRegistry([]),
            new StepTypeRegistry([]),
        );
    }

    #[Test]
    public function it_is_empty_when_no_step_has_started(): void
    {
        $run = $this->createRun();
        $step = $this->createStepRun($run, 'read', position: 0);

        $view = $this->timeline->for($run, [$step]);

        self::assertFalse($view->hasTiming());
        self::assertCount(1, $view->steps());
        self::assertTrue($view->steps()[0]->isPending());
        self::assertNull($view->steps()[0]->leftPercent());
        self::assertNull($view->steps()[0]->widthPercent());
    }

    #[Test]
    public function it_computes_proportional_bars_for_a_completed_run_with_parallel_steps(): void
    {
        $start = new DateTimeImmutable('2026-09-30 10:00:00.000');
        $run = $this->createRun($start);
        $read = $this->createStepRun($run, 'read', position: 0);
        $read->markRunning($start);
        $read->markCompleted(processedCount: 1, successCount: 1, finishedAt: $start->modify('+2000 ms'));

        $write = $this->createStepRun($run, 'write', position: 1);
        $write->markRunning($start->modify('+1000 ms'));
        $write->markCompleted(processedCount: 1, successCount: 1, finishedAt: $start->modify('+4000 ms'));
        $run->markCompleted($start->modify('+4000 ms'));

        $view = $this->timeline->for($run, [$read, $write]);

        self::assertTrue($view->hasTiming());
        self::assertFalse($view->isLive());

        $readView = $view->steps()[0];
        $writeView = $view->steps()[1];

        self::assertSame(0.0, $readView->leftPercent());
        self::assertSame(50.0, $readView->widthPercent());

        self::assertSame(25.0, $writeView->leftPercent());
        self::assertSame(75.0, $writeView->widthPercent());

        self::assertEquals($start, $view->windowStart());
        self::assertEquals($start->modify('+4000 ms'), $view->windowEnd());
        self::assertSame(4000, $view->totalDurationMs());
    }

    #[Test]
    public function it_marks_an_in_flight_step_as_running_on_a_live_run(): void
    {
        $start = new DateTimeImmutable('-10 seconds');
        $run = $this->createRun($start);
        $step = $this->createStepRun($run, 'write', position: 0);
        $step->markRunning($start);

        $view = $this->timeline->for($run, [$step]);

        self::assertTrue($view->isLive());
        self::assertTrue($view->steps()[0]->isRunning());
        self::assertSame(0.0, $view->steps()[0]->leftPercent());
        self::assertGreaterThan(0.0, $view->steps()[0]->widthPercent());
    }

    #[Test]
    public function it_clamps_tiny_durations_to_a_visible_width(): void
    {
        $start = new DateTimeImmutable('2026-09-30 10:00:00.000');
        $run = $this->createRun($start);
        $quick = $this->createStepRun($run, 'read', position: 0);
        $quick->markRunning($start);
        $quick->markCompleted(processedCount: 1, successCount: 1, finishedAt: $start->modify('+1 ms'));

        $long = $this->createStepRun($run, 'write', position: 1);
        $long->markRunning($start->modify('+1 ms'));
        $long->markCompleted(processedCount: 1, successCount: 1, finishedAt: $start->modify('+10000 ms'));
        $run->markCompleted($start->modify('+10000 ms'));

        $view = $this->timeline->for($run, [$quick, $long]);

        self::assertGreaterThanOrEqual(0.5, $view->steps()[0]->widthPercent());
    }

    #[Test]
    public function it_keeps_started_step_not_running_when_run_is_terminal(): void
    {
        $start = new DateTimeImmutable('2026-09-30 10:00:00.000');
        $run = $this->createRun($start);
        $step = $this->createStepRun($run, 'read', position: 0);
        $step->markRunning($start);
        $step->markFailed(errorMessage: 'boom', finishedAt: $start->modify('+1000 ms'));
        $run->markFailed(errorMessage: 'boom', finishedAt: $start->modify('+1000 ms'));

        $view = $this->timeline->for($run, [$step]);

        self::assertFalse($view->isLive());
        self::assertFalse($view->steps()[0]->isRunning());
    }

    private function createRun(?DateTimeImmutable $startedAt = null): WorkflowRun
    {
        $run = new WorkflowRun(
            runId: 'run-' . uniqid('', true),
            workflowName: 'contacts',
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
