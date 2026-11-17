<?php

declare(strict_types=1);

namespace Fluxx\Ui;

use Fluxx\Entity\Enum\WorkflowRunStatus;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowRunReport;
use Fluxx\Entity\WorkflowStepRun;
use Fluxx\Repository\WorkflowRunReportRepository;
use Fluxx\Repository\WorkflowRunRepository;
use Fluxx\Repository\WorkflowStepRunRepository;
use Fluxx\StepType\StepTypeRegistry;
use Fluxx\Workflow\Report\RunReportBuilder;
use Fluxx\Workflow\SynchronizationRegistry;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class RunDetails
{
    public const TAB_EXECUTION = 'execution';
    public const TAB_REPORT = 'report';

    public function __construct(
        private SynchronizationRegistry $registry,
        private StepTypeRegistry $stepTypeRegistry,
        private WorkflowRunRepository $workflowRunRepository,
        private WorkflowStepRunRepository $workflowStepRunRepository,
        private RunTimeline $runTimeline,
        private WorkflowRunReportRepository $workflowRunReportRepository,
    ) {
    }

    /**
     * @param string|null $requestedTab the tab asked in the URL; without one, a run that completed with
     *                                  warnings opens on its report so the warnings are seen
     */
    public function forWorkflowCodeAndRunId(string $workflowCode, string $runId, ?string $requestedTab = null): RunDetailView
    {
        $workflow = $this->registry->get($workflowCode);
        $definition = $workflow->definition();
        $run = $this->workflowRunRepository->findOneByRunId($runId);

        if ($run === null) {
            throw new InvalidArgumentException(sprintf('Run "%s" was not found.', $runId));
        }

        $errorPayload = $run->errorPayload();
        $relaunchMetadata = $run->relaunchMetadata();
        $cancellationMetadata = $run->cancellationMetadata();
        $stepRuns = $this->workflowStepRunRepository->findByWorkflowRunOrdered($run);
        $timeline = $this->runTimeline->for($run, $stepRuns);
        $storedReport = $this->workflowRunReportRepository->findOneByRun($run);
        $activeTab = self::resolveTab($storedReport !== null, $requestedTab, $run->status() === WorkflowRunStatus::CompletedWithWarnings);

        return new RunDetailView(
            workflowCode: $definition->code(),
            workflowName: $definition->name(),
            sourceSystem: $definition->sourceSystem(),
            targetSystem: $definition->targetSystem(),
            runId: $run->runId(),
            trigger: $run->trigger(),
            status: $run->status()->value,
            synchronous: $run->isSynchronous(),
            lockKey: $run->lockKey(),
            lockScope: $run->lockScope()?->value,
            relaunchMode: self::readString($relaunchMetadata, 'mode'),
            originalRunId: self::readString($relaunchMetadata, 'original_run_id'),
            restartStepCode: self::readString($relaunchMetadata, 'restart_step_code'),
            batchId: $run->batchId(),
            createdAt: $run->createdAt(),
            startedAt: $run->startedAt(),
            finishedAt: $run->finishedAt(),
            errorMessage: $run->errorMessage(),
            errorCategory: self::readString($errorPayload, 'category'),
            errorCode: self::readString($errorPayload, 'code'),
            errorClass: self::readString($errorPayload, 'class'),
            errorContext: self::readArray($errorPayload, 'context'),
            errorOccurredAt: self::readDate($errorPayload, 'occurred_at'),
            relaunchReason: self::readString($relaunchMetadata, 'reason'),
            relaunchOperator: self::readString($relaunchMetadata, 'operator_user'),
            relaunchTrigger: self::readString($relaunchMetadata, 'trigger'),
            cancelReason: self::readString($cancellationMetadata, 'reason'),
            cancelOperator: self::readString($cancellationMetadata, 'operator_user'),
            cancelTrigger: self::readString($cancellationMetadata, 'trigger'),
            cancelledAt: self::readString($cancellationMetadata, 'cancelled_at'),
            stepCount: $run->stepRuns()->count(),
            processedTotal: $this->sumStepCount($run, static fn ($step) => $step->processedCount()),
            successTotal: $this->sumStepCount($run, static fn ($step) => $step->successCount()),
            errorTotal: $this->sumStepCount($run, static fn ($step) => $step->errorCount()),
            steps: $this->buildStepViews($run, $stepRuns),
            timeline: $timeline,
            active: $run->status()->isActive(),
            parameters: $run->launchParameters(),
            relaunches: array_map(
                static fn (WorkflowRun $relaunch): RunRelaunchView => new RunRelaunchView(
                    runId: $relaunch->runId(),
                    status: $relaunch->status()->value,
                    createdAt: $relaunch->createdAt(),
                ),
                $this->workflowRunRepository->findRelaunchesOf($run),
            ),
            hasReport: $storedReport !== null,
            report: $storedReport !== null && $activeTab === self::TAB_REPORT ? self::readReport($storedReport, $timeline) : null,
            reportRebuildable: RunReportBuilder::isReportable($run),
            activeTab: $activeTab,
        );
    }

    private static function resolveTab(bool $hasReport, ?string $requestedTab, bool $completedWithWarnings): string
    {
        if (!$hasReport) {
            return self::TAB_EXECUTION;
        }

        return match ($requestedTab) {
            self::TAB_REPORT => self::TAB_REPORT,
            self::TAB_EXECUTION => self::TAB_EXECUTION,
            default => $completedWithWarnings ? self::TAB_REPORT : self::TAB_EXECUTION,
        };
    }

    /**
     * A report the page cannot read is shown as unavailable instead of breaking the page.
     */
    private static function readReport(WorkflowRunReport $report, RunTimelineView $timeline): ?RunReportView
    {
        try {
            return RunReportView::fromStored($report, $timeline);
        } catch (UnreadableRunReportException|\TypeError|\ValueError) {
            return null;
        }
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private static function readString(?array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function readArray(?array $payload, string $key): ?array
    {
        if (!is_array($payload)) {
            return null;
        }

        $value = $payload[$key] ?? null;

        if (!is_array($value) || $value === []) {
            return null;
        }

        return $value;
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private static function readDate(?array $payload, string $key): ?DateTimeImmutable
    {
        $value = $payload[$key] ?? null;

        if (!is_string($value)) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param callable(\Fluxx\Entity\WorkflowStepRun): int $selector
     */
    private function sumStepCount(WorkflowRun $run, callable $selector): int
    {
        $total = 0;

        foreach ($run->stepRuns() as $stepRun) {
            $total += $selector($stepRun);
        }

        return $total;
    }

    /**
     * @param list<\Fluxx\Entity\WorkflowStepRun> $stepRuns
     * @return list<WorkflowExecutionStepOverview>
     */
    private function buildStepViews(WorkflowRun $run, array $stepRuns): array
    {
        // Execution order: steps that started first come first, steps that never started keep
        // their definition position at the end.
        $stepRuns = array_values($stepRuns);
        usort($stepRuns, static function (WorkflowStepRun $a, WorkflowStepRun $b): int {
            return [$a->startedAt() === null, $a->startedAt(), $a->position()]
                <=> [$b->startedAt() === null, $b->startedAt(), $b->position()];
        });

        $views = [];

        foreach ($stepRuns as $stepRun) {
            $stepType = $this->stepTypeRegistry->get($stepRun->stepType());

            $views[] = new WorkflowExecutionStepOverview(
                type: $stepRun->stepType(),
                typeLabel: $stepType->label(),
                typeTone: $stepType->toneClass(),
                typeToneStyle: $stepType->toneStyle(),
                code: $stepRun->stepName(),
                name: $this->resolveStepName($run, $stepRun->stepName()),
                status: $stepRun->status()->value,
                processedCount: $stepRun->processedCount(),
                successCount: $stepRun->successCount(),
                errorCount: $stepRun->errorCount(),
                durationMs: $stepRun->durationMs(),
                memoryPeakBytes: $stepRun->memoryPeakBytes(),
                idempotenceKey: $stepRun->idempotenceKey(),
                deduplicationStatus: $stepRun->deduplicationStatus()->value,
                deduplicatedFromRunId: $stepRun->deduplicatedFromStepRun()?->workflowRun()->runId(),
                startedAt: $stepRun->startedAt(),
                finishedAt: $stepRun->finishedAt(),
            );
        }

        return $views;
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
}
