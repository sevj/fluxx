<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Runtime;

use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;
use Fluxx\Repository\WorkflowPayloadLookupInterface;
use Fluxx\Repository\WorkflowStepRunLookupInterface;
use Fluxx\Workflow\Context\WorkflowContext;
use Fluxx\Workflow\Payload\WorkflowPayloadStoreInterface;
use Fluxx\Workflow\Step\IdempotentWorkflowStepInterface;
use Fluxx\Workflow\Step\WorkflowStepInput;
use Fluxx\Workflow\WorkflowStepDefinition;
use RuntimeException;

final readonly class WorkflowIdempotenceResolver
{
    public function __construct(
        private WorkflowStepRunLookupInterface $workflowStepRunRepository,
        private WorkflowPayloadLookupInterface $workflowPayloadRepository,
        private WorkflowPayloadStoreInterface $workflowPayloadStore,
    ) {
    }

    public function resolveKey(
        WorkflowStepDefinition $stepDefinition,
        WorkflowContext $context,
        WorkflowStepInput $input,
    ): ?string {
        if ($stepDefinition->idempotence() === null) {
            return null;
        }

        $handler = $stepDefinition->handler();

        if (!$handler instanceof IdempotentWorkflowStepInterface) {
            throw new RuntimeException(sprintf(
                'Step "%s" enables idempotence but its handler does not implement %s.',
                $stepDefinition->code(),
                IdempotentWorkflowStepInterface::class,
            ));
        }

        $idempotenceKey = $handler->idempotenceKey($context, $input);

        if ($idempotenceKey === null) {
            return null;
        }

        $idempotenceKey = trim($idempotenceKey);

        return $idempotenceKey !== '' ? $idempotenceKey : null;
    }

    public function applyKey(
        WorkflowStepDefinition $stepDefinition,
        WorkflowStepRun $stepRun,
        WorkflowContext $context,
        WorkflowStepInput $input,
    ): ?string {
        $idempotenceKey = $this->resolveKey($stepDefinition, $context, $input);

        if ($idempotenceKey !== null) {
            $stepRun->markIdempotenceApplied($idempotenceKey);

            $metadata = $stepRun->metadata();
            $metadata['deduplication'] = [
                'status' => 'applied',
                'key' => $idempotenceKey,
                'strategy' => $stepDefinition->idempotence()?->strategy(),
            ];
            $stepRun->replaceMetadata($metadata);
        }

        return $idempotenceKey;
    }

    public function tryCompleteFromHit(
        WorkflowRun $workflowRun,
        WorkflowStepDefinition $stepDefinition,
        WorkflowStepRun $stepRun,
        WorkflowContext $context,
        WorkflowStepInput $input,
        int $stepStartedAt,
    ): bool {
        $idempotenceKey = $this->resolveKey($stepDefinition, $context, $input);

        if ($idempotenceKey === null) {
            return false;
        }

        $deduplicatedFrom = $this->workflowStepRunRepository->findLatestCompletedByWorkflowNameAndStepNameAndIdempotenceKey(
            $workflowRun->workflowName(),
            $stepDefinition->code(),
            $idempotenceKey,
        );

        if ($deduplicatedFrom === null) {
            $stepRun->markIdempotenceApplied($idempotenceKey);
            $stepRun->replaceMetadata([
                'deduplication' => [
                    'status' => 'applied',
                    'key' => $idempotenceKey,
                    'strategy' => $stepDefinition->idempotence()?->strategy(),
                ],
            ]);

            return false;
        }

        $stepRun->markDeduplicated($idempotenceKey, $deduplicatedFrom);
        $stepRun->replaceMetadata(array_merge(
            $deduplicatedFrom->metadata(),
            [
                'deduplication' => [
                    'status' => 'deduplicated',
                    'source_run_id' => $deduplicatedFrom->workflowRun()->runId(),
                    'source_step_run_id' => $deduplicatedFrom->id(),
                    'source_step_code' => $deduplicatedFrom->stepName(),
                ],
            ],
        ));

        $this->cloneDownstreamPayloads(
            workflowRun: $workflowRun,
            sourceStepRun: $deduplicatedFrom,
            targetStepRun: $stepRun,
        );

        $stepRun->markCompleted(
            processedCount: $deduplicatedFrom->processedCount(),
            successCount: $deduplicatedFrom->successCount(),
            errorCount: $deduplicatedFrom->errorCount(),
            durationMs: $this->computeDurationMs($stepStartedAt),
            memoryPeakBytes: $this->measurePeakMemoryBytes(),
        );

        return true;
    }

    private function cloneDownstreamPayloads(
        WorkflowRun $workflowRun,
        WorkflowStepRun $sourceStepRun,
        WorkflowStepRun $targetStepRun,
    ): void {
        foreach ($this->workflowPayloadRepository->findBySourceStepRunOrdered($sourceStepRun) as $payload) {
            $snapshot = $this->workflowPayloadStore->load($payload);
            $metadata = $snapshot['metadata'] ?? $payload->metadata();

            if (!is_array($metadata)) {
                $metadata = $payload->metadata();
            }

            $metadata['deduplication'] = [
                'status' => 'reused_payload',
                'source_run_id' => $sourceStepRun->workflowRun()->runId(),
                'source_step_run_id' => $sourceStepRun->id(),
            ];

            $records = $snapshot['records'] ?? [];

            $this->workflowPayloadStore->storeStepInput(
                workflowRun: $workflowRun,
                sourceStepRun: $targetStepRun,
                targetStepType: $payload->targetStepType(),
                targetStepName: $payload->targetStepName(),
                records: is_array($records) ? $records : [],
                recordCount: $payload->recordCount(),
                sequence: $payload->sequence(),
                metadata: $metadata,
            );
        }
    }

    private function computeDurationMs(int $stepStartedAt): int
    {
        return max(0, (int) floor((hrtime(true) - $stepStartedAt) / 1_000_000));
    }

    private function measurePeakMemoryBytes(): int
    {
        return max(memory_get_peak_usage(true), memory_get_usage(true));
    }
}
