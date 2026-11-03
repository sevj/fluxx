<?php

declare(strict_types=1);

namespace Fluxx\Ui;

use Fluxx\Entity\Enum\WorkflowRunStatus;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;
use Fluxx\StepType\StepTypeRegistry;
use Fluxx\Workflow\SynchronizationRegistry;
use Fluxx\Workflow\WorkflowDefinition;

final readonly class RunTimeline
{
    public function __construct(
        private SynchronizationRegistry $registry,
        private StepTypeRegistry $stepTypeRegistry,
    ) {
    }

    /**
     * @param list<WorkflowStepRun> $stepRuns
     */
    public function for(WorkflowRun $run, array $stepRuns): RunTimelineView
    {
        $now = new \DateTimeImmutable();
        $terminalStatuses = [
            WorkflowRunStatus::Completed,
            WorkflowRunStatus::Failed,
            WorkflowRunStatus::PartiallyFailed,
            WorkflowRunStatus::Cancelled,
        ];
        $isTerminal = in_array($run->status(), $terminalStatuses, true);

        $definition = $this->registry->has($run->workflowName())
            ? $this->registry->get($run->workflowName())->definition()
            : null;

        $windowStart = $run->startedAt() ?? $run->createdAt();
        $windowEnd = $isTerminal ? ($run->finishedAt() ?? $now) : $now;
        $isLive = !$isTerminal;

        $indexed = $this->indexStepRuns($stepRuns);
        $widthMs = $this->resolveWidthMs($indexed, $isTerminal, $now);
        $dependencies = $this->resolveDependencies($indexed, $definition);
        $levels = $this->resolveLevels($dependencies);

        // Real start times when the steps have them; otherwise the position a step would
        // have if it started as soon as its dependencies finished.
        $origin = $windowStart;
        foreach ($indexed as $stepRun) {
            if ($stepRun->startedAt() !== null && $stepRun->startedAt() < $origin) {
                $origin = $stepRun->startedAt();
            }
        }

        $simulatedOffsetMs = $this->resolveStartOffsets($indexed, $dependencies, $widthMs);
        $startOffsetMs = [];
        foreach ($indexed as $code => $stepRun) {
            $startOffsetMs[$code] = $stepRun->startedAt() !== null
                ? $this->dateDiffMs($stepRun->startedAt(), $origin)
                : ($simulatedOffsetMs[$code] ?? 0);
        }

        $totalMs = 1;
        foreach ($widthMs as $code => $width) {
            if ($width === null) {
                continue;
            }
            $end = ($startOffsetMs[$code] ?? 0) + $width;
            if ($end > $totalMs) {
                $totalMs = $end;
            }
        }

        $hasTiming = false;
        foreach ($widthMs as $width) {
            if ($width !== null && $width > 0) {
                $hasTiming = true;
                break;
            }
        }

        $stepViews = [];
        foreach ($stepRuns as $stepRun) {
            $code = $stepRun->stepName();
            $stepType = $this->stepTypeRegistry->get($stepRun->stepType());
            $startedAt = $stepRun->startedAt();
            $finishedAt = $stepRun->finishedAt();
            $isRunning = $startedAt !== null && $finishedAt === null && !$isTerminal;
            $width = $widthMs[$code] ?? null;
            $offset = $startOffsetMs[$code] ?? 0;

            $leftPercent = null;
            $widthPercent = null;

            if ($width !== null) {
                $leftPercent = max(0.0, min(100.0, ($offset / $totalMs) * 100.0));
                $rawWidth = ($width / $totalMs) * 100.0;
                $rawWidth = max(0.0, min(max(0.0, 100.0 - $leftPercent), $rawWidth));
                $widthPercent = max($width > 0 ? 0.5 : 0.0, $rawWidth);
            }

            $stepViews[] = new RunTimelineStepView(
                code: $code,
                name: $this->resolveStepName($definition, $code),
                status: $stepRun->status()->value,
                typeTone: $stepType->toneClass(),
                typeToneStyle: $stepType->toneStyle(),
                position: $stepRun->position(),
                startedAt: $startedAt,
                finishedAt: $finishedAt,
                durationMs: $stepRun->durationMs(),
                leftPercent: $leftPercent,
                widthPercent: $widthPercent,
                isRunning: $isRunning,
                level: $levels[$code] ?? 0,
            );
        }

        // Chronological order: parallel branches end up as neighbouring rows with overlapping bars.
        usort($stepViews, static function (RunTimelineStepView $a, RunTimelineStepView $b) use ($startOffsetMs): int {
            return [$startOffsetMs[$a->code()] ?? 0, $a->level(), $a->position()]
                <=> [$startOffsetMs[$b->code()] ?? 0, $b->level(), $b->position()];
        });

        return new RunTimelineView(
            windowStart: $windowStart,
            windowEnd: $windowEnd,
            totalDurationMs: $totalMs,
            isLive: $isLive,
            hasTiming: $hasTiming,
            steps: $stepViews,
        );
    }

    /**
     * @param list<WorkflowStepRun> $stepRuns
     * @return array<string, WorkflowStepRun>
     */
    private function indexStepRuns(array $stepRuns): array
    {
        $indexed = [];
        foreach ($stepRuns as $stepRun) {
            $indexed[$stepRun->stepName()] = $stepRun;
        }

        return $indexed;
    }

    /**
     * @param array<string, WorkflowStepRun> $indexed
     * @return array<string, ?int>
     */
    private function resolveWidthMs(array $indexed, bool $isTerminal, \DateTimeImmutable $now): array
    {
        $widths = [];
        foreach ($indexed as $code => $stepRun) {
            $finishedAt = $stepRun->finishedAt();
            $startedAt = $stepRun->startedAt();
            $duration = $stepRun->durationMs();

            if ($duration !== null) {
                $widths[$code] = max(0, $duration);
                continue;
            }

            if ($startedAt !== null && $finishedAt !== null) {
                $widths[$code] = max(0, $this->dateDiffMs($finishedAt, $startedAt));
                continue;
            }

            if ($startedAt !== null && !$isTerminal) {
                $widths[$code] = max(0, $this->dateDiffMs($now, $startedAt));
                continue;
            }

            $widths[$code] = null;
        }

        return $widths;
    }

    /**
     * @param array<string, list<string>> $dependencies
     * @return array<string, int>
     */
    private function resolveLevels(array $dependencies): array
    {
        $levels = [];

        $resolve = function (string $code, array $trail) use (&$resolve, &$levels, $dependencies): int {
            if (isset($levels[$code])) {
                return $levels[$code];
            }

            $level = 0;
            foreach ($dependencies[$code] ?? [] as $depCode) {
                if (isset($trail[$depCode]) || !isset($dependencies[$depCode])) {
                    continue;
                }

                $level = max($level, $resolve($depCode, $trail + [$code => true]) + 1);
            }

            return $levels[$code] = $level;
        };

        foreach (array_keys($dependencies) as $code) {
            $resolve($code, []);
        }

        return $levels;
    }

    /**
     * @param array<string, WorkflowStepRun> $indexed
     * @param array<string, list<string>> $dependencies
     * @param array<string, ?int> $widthMs
     * @return array<string, int>
     */
    private function resolveStartOffsets(array $indexed, array $dependencies, array $widthMs): array
    {
        $offsets = [];
        $pending = array_keys($indexed);

        while ($pending !== []) {
            $progressed = false;

            foreach ($pending as $index => $code) {
                $deps = $dependencies[$code] ?? [];
                $ready = true;

                foreach ($deps as $depCode) {
                    if (!isset($offsets[$depCode])) {
                        $ready = false;
                        break;
                    }
                }

                if (!$ready) {
                    continue;
                }

                $offset = 0;
                foreach ($deps as $depCode) {
                    $depWidth = $widthMs[$depCode] ?? 0;
                    $candidate = ($offsets[$depCode] ?? 0) + max(0, $depWidth);
                    if ($candidate > $offset) {
                        $offset = $candidate;
                    }
                }

                $offsets[$code] = $offset;
                unset($pending[$index]);
                $progressed = true;
            }

            if (!$progressed) {
                foreach ($pending as $code) {
                    $offsets[$code] = 0;
                }
                break;
            }
        }

        return $offsets;
    }

    /**
     * @param array<string, WorkflowStepRun> $indexed
     * @return array<string, list<string>>
     */
    private function resolveDependencies(array $indexed, ?WorkflowDefinition $definition): array
    {
        $dependencies = [];

        if ($definition === null) {
            return $dependencies;
        }

        foreach ($definition->steps() as $stepDefinition) {
            $code = $stepDefinition->code();
            if (!isset($indexed[$code])) {
                continue;
            }

            $deps = [];
            foreach ($stepDefinition->dependsOn() as $depCode) {
                if (isset($indexed[$depCode])) {
                    $deps[] = $depCode;
                }
            }
            $dependencies[$code] = $deps;
        }

        return $dependencies;
    }

    private function resolveStepName(?WorkflowDefinition $definition, string $stepCode): string
    {
        if ($definition === null) {
            return $stepCode;
        }

        foreach ($definition->steps() as $step) {
            if ($step->code() === $stepCode) {
                return $step->name();
            }
        }

        return $stepCode;
    }

    private function dateDiffMs(\DateTimeImmutable $end, \DateTimeImmutable $start): int
    {
        return max(0, (int) $end->format('Uv') - (int) $start->format('Uv'));
    }
}
