<?php

declare(strict_types=1);

namespace Fluxx\Ui;

use DateTimeImmutable;
use Fluxx\Entity\Enum\WorkflowStepRunStatus;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;
use Fluxx\StepType\StepTypeRegistry;
use Fluxx\Workflow\SynchronizationRegistry;
use Fluxx\Workflow\WorkflowDefinition;

final readonly class ActiveRunsViewFactory
{
    private const ACTIVITY_WINDOW_MINUTES = 15;
    private const IN_PROGRESS_STEP_STATUSES = [
        WorkflowStepRunStatus::Running,
        WorkflowStepRunStatus::Relaunched,
    ];

    public function __construct(
        private SynchronizationRegistry $registry,
        private StepTypeRegistry $stepTypeRegistry,
    ) {
    }

    public function row(
        WorkflowRun $run,
        array $stepRuns,
        array $workerNames,
        DateTimeImmutable $now,
    ): ActiveRunRowView {
        $definition = $this->registry->has($run->workflowName())
            ? $this->registry->get($run->workflowName())->definition()
            : null;
        $latestByStepName = $this->indexLatestByStepName($stepRuns);
        $totalSteps = max(count($definition?->steps() ?? []), count($latestByStepName));

        $completedSteps = 0;
        $failedSteps = 0;
        $currentSteps = [];

        foreach ($latestByStepName as $stepRun) {
            $status = $stepRun->status();

            if ($status === WorkflowStepRunStatus::Completed) {
                ++$completedSteps;
                continue;
            }

            if ($status === WorkflowStepRunStatus::Failed) {
                ++$failedSteps;
                continue;
            }

            if (in_array($status, self::IN_PROGRESS_STEP_STATUSES, true) && $stepRun->finishedAt() === null) {
                $currentSteps[] = $this->stepView($stepRun, $definition, $now);
            }
        }

        $progressPercent = $totalSteps > 0
            ? min(100.0, round(($completedSteps / $totalSteps) * 100.0, 1))
            : 0.0;

        return new ActiveRunRowView(
            runId: $run->runId(),
            workflowCode: $run->workflowName(),
            workflowName: $definition?->name(),
            sourceSystem: $run->sourceSystem(),
            targetSystem: $run->targetSystem(),
            trigger: $run->trigger(),
            status: $run->status()->value,
            createdAt: $run->createdAt(),
            startedAt: $run->startedAt(),
            durationLabel: $this->formatDuration($this->elapsedMs($run->startedAt() ?? $run->createdAt(), $now)),
            totalSteps: $totalSteps,
            completedSteps: $completedSteps,
            failedSteps: $failedSteps,
            progressPercent: $progressPercent,
            currentSteps: $currentSteps,
            workerNames: $workerNames,
        );
    }

    public function activity(WorkflowStepRun $stepRun, DateTimeImmutable $now): ?ActiveRunActivityView
    {
        $occurredAt = $stepRun->finishedAt() ?? $stepRun->startedAt() ?? $stepRun->createdAt();

        if ($occurredAt < $now->modify('-' . self::ACTIVITY_WINDOW_MINUTES . ' minutes')) {
            return null;
        }

        $workflowCode = $stepRun->workflowRun()->workflowName();
        $definition = $this->registry->has($workflowCode)
            ? $this->registry->get($workflowCode)->definition()
            : null;
        $stepType = $this->stepTypeRegistry->get($stepRun->stepType());

        return new ActiveRunActivityView(
            occurredAt: $occurredAt,
            kind: $this->activityKind($stepRun),
            stepCode: $stepRun->stepName(),
            stepName: $this->resolveStepName($definition, $stepRun->stepName()),
            runId: $stepRun->workflowRun()->runId(),
            workflowCode: $workflowCode,
            workflowName: $definition?->name(),
            typeTone: $stepType->toneClass(),
            typeToneStyle: $stepType->toneStyle(),
            errorMessage: $stepRun->errorMessage(),
        );
    }

    /**
     * @param list<WorkflowStepRun> $stepRuns
     * @return array<string, WorkflowStepRun>
     */
    private function indexLatestByStepName(array $stepRuns): array
    {
        $latest = [];

        foreach ($stepRuns as $stepRun) {
            $latest[$stepRun->stepName()] = $stepRun;
        }

        return $latest;
    }

    private function stepView(
        WorkflowStepRun $stepRun,
        WorkflowDefinition|null $definition,
        DateTimeImmutable $now,
    ): ActiveRunStepView {
        $startedAt = $stepRun->startedAt();

        return new ActiveRunStepView(
            code: $stepRun->stepName(),
            name: $this->resolveStepName($definition, $stepRun->stepName()),
            status: $stepRun->status()->value,
            typeTone: $this->stepTypeRegistry->get($stepRun->stepType())->toneClass(),
            typeToneStyle: $this->stepTypeRegistry->get($stepRun->stepType())->toneStyle(),
            startedAt: $startedAt,
            elapsedLabel: $startedAt !== null
                ? $this->formatDuration($this->elapsedMs($startedAt, $now))
                : null,
        );
    }

    private function activityKind(WorkflowStepRun $stepRun): string
    {
        return match ($stepRun->status()) {
            WorkflowStepRunStatus::Completed => 'completed',
            WorkflowStepRunStatus::Failed => 'failed',
            WorkflowStepRunStatus::Running => 'running',
            WorkflowStepRunStatus::Retrying, WorkflowStepRunStatus::Relaunched => 'retrying',
            default => 'pending',
        };
    }

    private function resolveStepName(?WorkflowDefinition $definition, string $stepCode): string
    {
        foreach ($definition?->steps() ?? [] as $step) {
            if ($step->code() === $stepCode) {
                return $step->name();
            }
        }

        return $stepCode;
    }

    private function elapsedMs(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return max(0, (int) $to->format('Uv') - (int) $from->format('Uv'));
    }

    private function formatDuration(int $durationMs): string
    {
        $seconds = (int) floor($durationMs / 1000);

        if ($seconds < 60) {
            return sprintf('%ds', $seconds);
        }

        $minutes = (int) floor($seconds / 60);
        $remainingSeconds = $seconds % 60;

        if ($minutes < 60) {
            return sprintf('%dm %02ds', $minutes, $remainingSeconds);
        }

        $hours = (int) floor($minutes / 60);
        $remainingMinutes = $minutes % 60;

        return sprintf('%dh %02dm', $hours, $remainingMinutes);
    }
}
