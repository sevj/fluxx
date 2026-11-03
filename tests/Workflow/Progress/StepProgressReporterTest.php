<?php

declare(strict_types=1);

namespace Fluxx\Tests\Workflow\Progress;

use Fluxx\Workflow\Progress\StepProgress;
use Fluxx\Workflow\Progress\StepProgressReporter;
use Fluxx\Workflow\Progress\StepProgressStoreInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StepProgressReporterTest extends TestCase
{
    private float $time = 100.0;

    /** @var list<array{0: string, 1: string, 2: ?StepProgress}> */
    private array $calls = [];

    #[Test]
    public function it_ignores_reports_made_outside_of_a_step(): void
    {
        $this->reporter()->report(1, 2);

        self::assertSame([], $this->calls);
    }

    #[Test]
    public function it_throttles_writes_within_the_same_phase(): void
    {
        $reporter = $this->reporter();
        $reporter->start('run', 'step');

        $reporter->report(1, 10, 'a');
        $this->time += 0.5;
        $reporter->report(2, 10, 'a');
        $this->time += 0.6;
        $reporter->report(3, 10, 'a');

        self::assertSame([1, 3], array_map(static fn (array $c): int => $c[2]->done(), $this->calls));
    }

    #[Test]
    public function it_writes_immediately_when_the_phase_changes(): void
    {
        $reporter = $this->reporter();
        $reporter->start('run', 'step');

        $reporter->report(1, 10, 'a');
        $reporter->report(1, 5, 'b');

        self::assertSame(['a', 'b'], array_map(static fn (array $c): string => $c[2]->label(), $this->calls));
    }

    #[Test]
    public function it_clears_progress_and_stops_reporting_when_the_step_finishes(): void
    {
        $reporter = $this->reporter();
        $reporter->start('run', 'step');
        $reporter->report(1, 10);
        $reporter->finish();
        $this->time += 5;
        $reporter->report(2, 10);

        self::assertSame(['save', 'clear'], array_column($this->calls, 0));
    }

    #[Test]
    public function it_computes_a_bounded_percentage(): void
    {
        self::assertSame(33, (new StepProgress(1, 3))->percent());
        self::assertSame(100, (new StepProgress(5, 3))->percent());
        self::assertSame(0, (new StepProgress(1, 0))->percent());
    }

    private function reporter(): StepProgressReporter
    {
        $calls = &$this->calls;
        $store = new class($calls) implements StepProgressStoreInterface {
            public function __construct(private array &$calls)
            {
            }

            public function save(string $runId, string $stepCode, StepProgress $progress): void
            {
                $this->calls[] = ['save', $stepCode, $progress];
            }

            public function clear(string $runId, string $stepCode): void
            {
                $this->calls[] = ['clear', $stepCode, null];
            }

            public function forRun(string $runId): array
            {
                return [];
            }
        };

        return new StepProgressReporter($store, fn (): float => $this->time);
    }
}
