<?php

declare(strict_types=1);

namespace Fluxx\Ui;

use Fluxx\Entity\Enum\WorkflowRunStatus;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;
use Fluxx\StepType\StepTypeRegistry;
use Fluxx\Workflow\SynchronizationRegistry;

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

        $windowStart = null;

        foreach ($stepRuns as $stepRun) {
            $startedAt = $stepRun->startedAt();
            if ($startedAt !== null && ($windowStart === null || $startedAt < $windowStart)) {
                $windowStart = $startedAt;
            }
        }

        $windowStart ??= $run->startedAt() ?? $run->createdAt();

        if ($isTerminal) {
            $windowEnd = $run->finishedAt() ?? $run->createdAt();
            foreach ($stepRuns as $stepRun) {
                $finishedAt = $stepRun->finishedAt();
                if ($finishedAt !== null && $finishedAt > $windowEnd) {
                    $windowEnd = $finishedAt;
                }
            }
            $isLive = false;
        } else {
            $windowEnd = $now;
            $isLive = true;
        }

        $totalDurationMs = max(1, $this->dateDiffMs($windowEnd, $windowStart));
        $hasTiming = false;

        foreach ($stepRuns as $stepRun) {
            if ($stepRun->startedAt() !== null) {
                $hasTiming = true;
                break;
            }
        }

        $stepViews = [];

        foreach ($stepRuns as $stepRun) {
            $startedAt = $stepRun->startedAt();
            $finishedAt = $stepRun->finishedAt();
            $stepType = $this->stepTypeRegistry->get($stepRun->stepType());
            $isRunning = $startedAt !== null && $finishedAt === null && !$isTerminal;

            $leftPercent = null;
            $widthPercent = null;

            if ($startedAt !== null) {
                $effectiveFinish = $finishedAt ?? $windowEnd;
                $leftPercent = $this->dateDiffMs($startedAt, $windowStart) / $totalDurationMs * 100.0;
                $widthPercent = $this->dateDiffMs($effectiveFinish, $startedAt) / $totalDurationMs * 100.0;

                $leftPercent = max(0.0, min(100.0, $leftPercent));
                $widthPercent = max(0.5, min(max(0.0, 100.0 - $leftPercent), $widthPercent));
            }

            $stepViews[] = new RunTimelineStepView(
                code: $stepRun->stepName(),
                name: $this->resolveStepName($run, $stepRun->stepName()),
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
            );
        }

        return new RunTimelineView(
            windowStart: $windowStart,
            windowEnd: $windowEnd,
            totalDurationMs: $totalDurationMs,
            isLive: $isLive,
            hasTiming: $hasTiming,
            steps: $stepViews,
        );
    }

    private function resolveStepName(WorkflowRun $run, string $stepCode): string
    {
        $definition = $this->registry->has($run->workflowName())
            ? $this->registry->get($run->workflowName())->definition()
            : null;

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
