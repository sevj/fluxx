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

        $errorPayload = $run->errorPayload();
        $relaunchMetadata = $run->relaunchMetadata();
        $cancellationMetadata = $run->cancellationMetadata();

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
            steps: $this->buildStepViews($run),
        );
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
