<?php

declare(strict_types=1);

namespace Fluxx\Ui;

use Fluxx\Entity\WorkflowPayload;
use Fluxx\Repository\WorkflowPayloadRepository;
use Fluxx\Repository\WorkflowStepRunRepository;
use Fluxx\StepType\StepTypeRegistry;
use Fluxx\Workflow\Payload\WorkflowPayloadStore;
use Fluxx\Workflow\SynchronizationRegistry;
use InvalidArgumentException;

final readonly class StepRunDetails
{
    public function __construct(
        private SynchronizationRegistry $registry,
        private StepTypeRegistry $stepTypeRegistry,
        private WorkflowStepRunRepository $workflowStepRunRepository,
        private WorkflowPayloadRepository $workflowPayloadRepository,
        private WorkflowPayloadStore $workflowPayloadStore,
    ) {
    }

    public function forWorkflowRunAndStepCode(string $workflowCode, string $runId, string $stepCode): StepRunDetailView
    {
        $workflow = $this->registry->get($workflowCode);
        $definition = $workflow->definition();
        $stepDefinition = $this->resolveStep($workflowCode, $stepCode);
        $stepRun = $this->workflowStepRunRepository->findOneByWorkflowNameRunIdAndStepName($workflowCode, $runId, $stepCode);

        if ($stepRun === null) {
            throw new InvalidArgumentException(sprintf(
                'Step run "%s/%s/%s" was not found.',
                $workflowCode,
                $runId,
                $stepCode,
            ));
        }

        $inputPayloads = $this->buildPayloadViews(
            $this->workflowPayloadRepository->findByWorkflowRunAndTargetStepNameOrdered(
                $stepRun->workflowRun(),
                $stepCode,
            ),
        );

        $outputPayloads = $this->buildPayloadViews(
            $this->workflowPayloadRepository->findBySourceStepRunOrdered($stepRun),
        );

        return new StepRunDetailView(
            workflowCode: $definition->code(),
            workflowName: $definition->name(),
            workflowSourceSystem: $definition->sourceSystem(),
            workflowTargetSystem: $definition->targetSystem(),
            runId: $stepRun->workflowRun()->runId(),
            workflowStatus: $stepRun->workflowRun()->status()->value,
            workflowLockKey: $stepRun->workflowRun()->lockKey(),
            workflowLockScope: $stepRun->workflowRun()->lockScope()?->value,
            workflowRelaunchMode: is_string($stepRun->workflowRun()->relaunchMetadata()['mode'] ?? null) ? $stepRun->workflowRun()->relaunchMetadata()['mode'] : null,
            workflowOriginalRunId: is_string($stepRun->workflowRun()->relaunchMetadata()['original_run_id'] ?? null) ? $stepRun->workflowRun()->relaunchMetadata()['original_run_id'] : null,
            workflowRestartStepCode: is_string($stepRun->workflowRun()->relaunchMetadata()['restart_step_code'] ?? null) ? $stepRun->workflowRun()->relaunchMetadata()['restart_step_code'] : null,
            trigger: $stepRun->workflowRun()->trigger(),
            stepType: $stepDefinition['type'],
            stepTypeLabel: $stepDefinition['typeLabel'],
            stepTypeTone: $stepDefinition['typeTone'],
            stepTypeToneStyle: $stepDefinition['typeToneStyle'],
            stepCode: $stepDefinition['code'],
            stepName: $stepDefinition['name'],
            stepStatus: $stepRun->status()->value,
            processedCount: $stepRun->processedCount(),
            successCount: $stepRun->successCount(),
            errorCount: $stepRun->errorCount(),
            durationMs: $stepRun->durationMs(),
            memoryPeakBytes: $stepRun->memoryPeakBytes(),
            retryCount: $stepRun->retryCount(),
            lastRetryAt: $stepRun->lastRetryAt(),
            nextRetryAt: $stepRun->nextRetryAt(),
            createdAt: $stepRun->createdAt(),
            startedAt: $stepRun->startedAt(),
            finishedAt: $stepRun->finishedAt(),
            errorMessage: $stepRun->errorMessage(),
            errorCategory: is_string($stepRun->errorPayload()['category'] ?? null) ? $stepRun->errorPayload()['category'] : null,
            errorCode: is_string($stepRun->errorPayload()['code'] ?? null) ? $stepRun->errorPayload()['code'] : null,
            idempotenceKey: $stepRun->idempotenceKey(),
            deduplicationStatus: $stepRun->deduplicationStatus()->value,
            deduplicatedFromRunId: $stepRun->deduplicatedFromStepRun()?->workflowRun()->runId(),
            deduplicatedFromStepCode: $stepRun->deduplicatedFromStepRun()?->stepName(),
            metadata: $stepRun->metadata(),
            inputPayloads: $inputPayloads,
            outputPayloads: $outputPayloads,
        );
    }

    /**
     * @return array{type: string, typeLabel: string, typeTone: string, typeToneStyle: ?string, code: string, name: string}
     */
    private function resolveStep(string $workflowCode, string $stepCode): array
    {
        $definition = $this->registry->get($workflowCode)->definition();

        foreach ($definition->steps() as $step) {
            if ($step->code() === $stepCode) {
                $type = $this->stepTypeRegistry->get($step->type());

                return [
                    'type' => $step->type(),
                    'typeLabel' => $type->label(),
                    'typeTone' => $type->toneClass(),
                    'typeToneStyle' => $type->toneStyle(),
                    'code' => $step->code(),
                    'name' => $step->name(),
                ];
            }
        }

        throw new InvalidArgumentException(sprintf('Step "%s" is not registered for workflow "%s".', $stepCode, $workflowCode));
    }

    /**
     * @param list<WorkflowPayload> $payloads
     * @return list<WorkflowPayloadView>
     */
    private function buildPayloadViews(array $payloads): array
    {
        return array_map(
            fn (WorkflowPayload $payload): WorkflowPayloadView => new WorkflowPayloadView(
                id: $payload->id() ?? 0,
                sequence: $payload->sequence(),
                recordCount: $payload->recordCount(),
                rawSize: $payload->rawSize(),
                storedSize: $payload->storedSize(),
                format: $payload->format(),
                compression: $payload->compression(),
                storageMode: $payload->storageMode(),
                contentHash: $payload->contentHash(),
                createdAt: $payload->createdAt(),
                metadata: $payload->metadata(),
                snapshot: $this->workflowPayloadStore->load($payload),
            ),
            $payloads,
        );
    }
}
