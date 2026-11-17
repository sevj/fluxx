<?php

declare(strict_types=1);

namespace Fluxx\Tests\Workflow\Report;

use DateTimeImmutable;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;
use Fluxx\Tests\Fixture\StubExecutableStep;
use Fluxx\Workflow\Report\CriticalPath;
use Fluxx\Workflow\WorkflowDefinition;
use Fluxx\Workflow\WorkflowStepDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CriticalPathTest extends TestCase
{
    /**
     * read ─┬─ companies ─┬─ join ── links
     *       ├─ contacts ──┘
     *       └─ verify
     */
    private function definition(): WorkflowDefinition
    {
        return new WorkflowDefinition(
            code: 'sync',
            name: 'Sync',
            sourceSystem: 'src',
            targetSystem: 'tgt',
            steps: [
                $this->step('read'),
                $this->step('companies', ['read']),
                $this->step('contacts', ['read']),
                $this->step('verify', ['read']),
                $this->step('join', ['companies', 'contacts']),
                $this->step('links', ['join']),
            ],
        );
    }

    #[Test]
    public function critical_path_follows_latest_finishing_dependency(): void
    {
        $stepRuns = $this->stepRuns([
            'read' => ['00:00', '00:10'],
            'companies' => ['00:10', '00:50'],
            'contacts' => ['00:10', '00:30'],
            'verify' => ['00:10', '00:20'],
            'join' => ['00:50', '00:51'],
            'links' => ['00:51', '01:40'],
        ]);

        self::assertSame(['read', 'companies', 'join', 'links'], CriticalPath::compute($this->definition(), $stepRuns));
    }

    #[Test]
    public function the_path_ends_with_the_step_that_finished_last_even_on_a_side_branch(): void
    {
        $stepRuns = $this->stepRuns([
            'read' => ['00:00', '00:10'],
            'companies' => ['00:10', '00:50'],
            'contacts' => ['00:10', '00:30'],
            'verify' => ['00:10', '02:00'],
            'join' => ['00:50', '00:51'],
            'links' => ['00:51', '01:40'],
        ]);

        self::assertSame(['read', 'verify'], CriticalPath::compute($this->definition(), $stepRuns));
    }

    #[Test]
    public function on_equal_finish_times_the_longer_dependency_is_on_the_path(): void
    {
        $stepRuns = $this->stepRuns([
            'read' => ['00:00', '00:10'],
            'companies' => ['00:40', '00:50'],
            'contacts' => ['00:10', '00:50'],
            'verify' => ['00:10', '00:20'],
            'join' => ['00:50', '00:50'],
            'links' => ['00:50', '01:40'],
        ]);

        self::assertSame(['read', 'contacts', 'join', 'links'], CriticalPath::compute($this->definition(), $stepRuns));
    }

    #[Test]
    public function unfinished_and_unknown_steps_are_left_out(): void
    {
        $stepRuns = $this->stepRuns([
            'read' => ['00:00', '00:10'],
            'companies' => ['00:10', null],
            'removed_step' => ['00:10', '05:00'],
        ]);

        self::assertSame(['read'], CriticalPath::compute($this->definition(), $stepRuns));
    }

    /**
     * @param list<string> $dependsOn
     */
    private function step(string $code, array $dependsOn = []): WorkflowStepDefinition
    {
        return new WorkflowStepDefinition($code, ucfirst($code), 'transform', new StubExecutableStep($code, $code), $dependsOn);
    }

    /**
     * @param array<string, array{string, ?string}> $timings minutes:seconds from the run start
     * @return array<string, WorkflowStepRun>
     */
    private function stepRuns(array $timings): array
    {
        $run = new WorkflowRun('run', 'sync', 'src', 'tgt', 'manual');
        $origin = new DateTimeImmutable('2026-11-17 08:00:00');
        $stepRuns = [];
        $position = 0;

        foreach ($timings as $code => [$start, $end]) {
            $stepRun = new WorkflowStepRun($run, 'transform', $code, ++$position);
            $startedAt = $this->at($origin, $start);
            $stepRun->markRunning($startedAt);

            if ($end !== null) {
                $finishedAt = $this->at($origin, $end);
                $stepRun->markCompleted(1, 1, durationMs: ((int) $finishedAt->format('U') - (int) $startedAt->format('U')) * 1000, finishedAt: $finishedAt);
            }

            $stepRuns[$code] = $stepRun;
        }

        return $stepRuns;
    }

    private function at(DateTimeImmutable $origin, string $offset): DateTimeImmutable
    {
        [$minutes, $seconds] = array_map('intval', explode(':', $offset));

        return $origin->modify(sprintf('+%d seconds', $minutes * 60 + $seconds));
    }
}
