<?php

declare(strict_types=1);

namespace Fluxx\Ui;

use Fluxx\Entity\WorkflowRun;
use Fluxx\Repository\WorkflowRunRepository;
use Fluxx\Repository\WorkflowStepRunRepository;
use Fluxx\StepType\StepTypeRegistry;
use Fluxx\Workflow\SynchronizationRegistry;
use InvalidArgumentException;

final readonly class RunDetails
{
    public function __construct(
        private SynchronizationRegistry $registry,
        private StepTypeRegistry $stepTypeRegistry,
        private WorkflowRunRepository $workflowRunRepository,
        private WorkflowStepRunRepository $workflowStepRunRepository,
    ) {
    }

    public function forWorkflowCodeAndRunId(string $workflowCode, string $runId): RunDetailView
    {
        $workflow = $this->registry->get($workflowCode);
        $definition = $workflow->definition();
        $run = $this->workflowRunRepository->findOneByRunId($runId);

        if ($run === null) {
            throw new InvalidArgumentException(sprintf('Run "%s" was not found.', $runId));
        }

        return new RunDetailView(
            workflowCode: $definition->code(),
            workflowName: $definition->name(),
            sourceSystem: $definition->sourceSystem(),
            targetSystem: $definition->targetSystem(),
            runId: $run->runId(),
            trigger: $run->trigger(),
            status: $run->status()->value,
            lockKey: $run->lockKey(),
            lockScope: $run->lockScope()?->value,
            relaunchMode: is_string($run->relaunchMetadata()['mode'] ?? null) ? $run->relaunchMetadata()['mode'] : null,
            originalRunId: is_string($run->relaunchMetadata()['original_run_id'] ?? null) ? $run->relaunchMetadata()['original_run_id'] : null,
            restartStepCode: is_string($run->relaunchMetadata()['restart_step_code'] ?? null) ? $run->relaunchMetadata()['restart_step_code'] : null,
            batchId: $run->batchId(),
            createdAt: $run->createdAt(),
            startedAt: $run->startedAt(),
            finishedAt: $run->finishedAt(),
            errorMessage: $run->errorMessage(),
            errorCategory: is_string($run->errorPayload()['category'] ?? null) ? $run->errorPayload()['category'] : null,
            stepCount: $run->stepRuns()->count(),
            processedTotal: $this->sumStepCount($run, static fn ($step) => $step->processedCount()),
            successTotal: $this->sumStepCount($run, static fn ($step) => $step->successCount()),
            errorTotal: $this->sumStepCount($run, static fn ($step) => $step->errorCount()),
            steps: $this->buildStepViews($run),
        );
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
     * @return list<WorkflowExecutionStepOverview>
     */
    private function buildStepViews(WorkflowRun $run): array
    {
        $steps = $this->workflowStepRunRepository->findByWorkflowRunOrdered($run);
        $views = [];

        foreach ($steps as $stepRun) {
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
