<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Runtime;

use Doctrine\ORM\EntityManagerInterface;
use Fluxx\Entity\Enum\WorkflowRunStatus;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;
use Fluxx\Entity\Enum\WorkflowStepRunStatus;
use Fluxx\Repository\WorkflowPayloadLookupInterface;
use Fluxx\Repository\WorkflowRunLookupInterface;
use Fluxx\Repository\WorkflowStepRunLookupInterface;
use Fluxx\Workflow\Context\WorkflowContextFactory;
use Fluxx\Workflow\Error\WorkflowErrorPayloadFactory;
use Fluxx\Workflow\Lock\WorkflowExecutionLockManagerInterface;
use Fluxx\Workflow\Payload\WorkflowPayloadStoreInterface;
use Fluxx\Workflow\SynchronizationRegistry;
use Fluxx\Workflow\WorkflowDefinition;
use Fluxx\Workflow\WorkflowStepDefinition;
use Fluxx\Workflow\Step\WorkflowStepInput;
use Fluxx\Workflow\Step\WorkflowStepInputPayload;
use RuntimeException;
use Throwable;

final readonly class FluxxRuntime
{
    public function __construct(
        private SynchronizationRegistry $registry,
        private EntityManagerInterface $entityManager,
        private WorkflowRunLookupInterface $workflowRunRepository,
        private WorkflowStepRunLookupInterface $workflowStepRunRepository,
        private WorkflowPayloadLookupInterface $workflowPayloadRepository,
        private WorkflowPayloadStoreInterface $workflowPayloadStore,
        private WorkflowContextFactory $workflowContextFactory,
        private WorkflowExecutionLockManagerInterface $workflowExecutionLockManager,
        private WorkflowErrorPayloadFactory $workflowErrorPayloadFactory,
        private WorkflowRunCompletionDecider $workflowRunCompletionDecider,
        private WorkflowCancellationSynchronizer $cancellationSynchronizer,
        private WorkflowRetryScheduler $retryScheduler,
        private WorkflowIdempotenceResolver $idempotenceResolver,
    ) {
    }

    /**
     * @return list<array{code: string, type: string}>
     */
    public function runStep(string $runId, string $stepCode): array
    {
        $workflowRun = $this->getWorkflowRun($runId);

        if (in_array($workflowRun->status(), [
            WorkflowRunStatus::Completed,
            WorkflowRunStatus::Failed,
            WorkflowRunStatus::PartiallyFailed,
            WorkflowRunStatus::Cancelled,
        ], true)) {
            return [];
        }

        $workflow = $this->registry->get($workflowRun->workflowName());
        $definition = $workflow->definition();
        $stepDefinition = $definition->step($stepCode);

        $existingStepRun = $this->workflowStepRunRepository->findLatestByWorkflowRunAndStepName($workflowRun, $stepCode);
        if ($existingStepRun?->status() === WorkflowStepRunStatus::Completed) {
            return $this->collectRunnableDownstreamSteps($workflowRun, $definition, $stepCode);
        }

        if (!$this->dependenciesAreSatisfied($workflowRun, $stepDefinition)) {
            return [];
        }

        $context = $this->workflowContextFactory->createFromRun($workflowRun, $definition);
        $workflowRun->markRunning();
        $input = $this->buildStepInput($workflowRun, $stepCode);

        $stepRun = $this->prepareStepRun(
            workflowRun: $workflowRun,
            stepDefinition: $stepDefinition,
            position: $definition->positionOf($stepCode),
            existingStepRun: $existingStepRun,
        );

        $stepStartedAt = hrtime(true);
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }

        $this->entityManager->beginTransaction();

        try {
            if ($this->idempotenceResolver->tryCompleteFromHit($workflowRun, $stepDefinition, $stepRun, $context, $input, $stepStartedAt)) {
                $this->finalizeWorkflowRunState($workflowRun, $definition);
                $this->entityManager->flush();
                $this->entityManager->commit();

                return $this->collectRunnableDownstreamSteps($workflowRun, $definition, $stepCode);
            }

            $result = $stepDefinition->handler()->execute(
                $context,
                $input,
            );

            $stepRun->replaceMetadata($result->metadata());
            $this->idempotenceResolver->applyKey($stepDefinition, $stepRun, $context, $input);

            foreach ($definition->downstreamSteps($stepCode) as $downstreamStep) {
                $output = $result->outputFor($downstreamStep->code());

                $this->workflowPayloadStore->storeStepInput(
                    workflowRun: $workflowRun,
                    sourceStepRun: $stepRun,
                    targetStepType: $downstreamStep->type(),
                    targetStepName: $downstreamStep->code(),
                    records: $output->records(),
                    recordCount: $output->recordCount(),
                    metadata: [
                        'workflow_code' => $context->workflowCode(),
                        'source_step_code' => $stepRun->stepName(),
                        'step_metadata' => $output->metadata(),
                    ],
                );
            }

            $stepRun->markCompleted(
                processedCount: $result->processedCount(),
                successCount: $result->successCount(),
                errorCount: $result->errorCount(),
                durationMs: $this->computeDurationMs($stepStartedAt),
                memoryPeakBytes: $this->measurePeakMemoryBytes(),
            );

            if ($this->cancellationSynchronizer->synchronizeIfNeeded($workflowRun)) {
                $this->entityManager->flush();
                $this->entityManager->commit();

                return [];
            }

            $this->finalizeWorkflowRunState($workflowRun, $definition);
            $this->entityManager->flush();
            $this->entityManager->commit();

            return $this->collectRunnableDownstreamSteps($workflowRun, $definition, $stepCode);
        } catch (Throwable $throwable) {
            $this->rollbackIfActive();

            return $this->handleStepError(
                workflowRun: $workflowRun,
                definition: $definition,
                stepDefinition: $stepDefinition,
                stepRun: $stepRun,
                throwable: $throwable,
                stepStartedAt: $stepStartedAt,
            );
        }
    }

    /**
     * @return list<array{code: string, type: string}>
     */
    private function handleStepError(
        WorkflowRun $workflowRun,
        WorkflowDefinition $definition,
        WorkflowStepDefinition $stepDefinition,
        WorkflowStepRun $stepRun,
        Throwable $throwable,
        int $stepStartedAt,
    ): array {
        $errorPayload = $this->workflowErrorPayloadFactory->fromThrowable($throwable);
        $retryPolicy = $this->retryScheduler->resolvePolicy($definition, $stepDefinition);
        $durationMs = $this->computeDurationMs($stepStartedAt);
        $memoryPeakBytes = $this->measurePeakMemoryBytes();

        $this->entityManager->beginTransaction();

        try {
            if ($this->retryScheduler->scheduleIfNeeded($workflowRun, $stepRun, $retryPolicy, $throwable->getMessage(), $errorPayload, $durationMs, $memoryPeakBytes)) {
                $this->entityManager->flush();
                $this->entityManager->commit();

                return [];
            }

            $stepRun->markFailed(
                errorMessage: $throwable->getMessage(),
                errorCount: 1,
                durationMs: $durationMs,
                memoryPeakBytes: $memoryPeakBytes,
                errorPayload: $errorPayload,
            );
            $this->finalizeWorkflowRunState($workflowRun, $definition, $throwable->getMessage(), $errorPayload);
            $this->entityManager->flush();
            $this->entityManager->commit();

            throw $throwable;
        } catch (Throwable $errorHandlingThrowable) {
            $this->rollbackIfActive();

            throw $errorHandlingThrowable;
        }
    }

    private function rollbackIfActive(): void
    {
        $connection = $this->entityManager->getConnection();

        if ($connection->isTransactionActive()) {
            $this->entityManager->rollback();
        }
    }

    private function getWorkflowRun(string $runId): WorkflowRun
    {
        $workflowRun = $this->workflowRunRepository->findOneByRunId($runId);

        if ($workflowRun === null) {
            throw new RuntimeException(sprintf('Workflow run "%s" was not found.', $runId));
        }

        return $workflowRun;
    }

    private function prepareStepRun(
        WorkflowRun $workflowRun,
        WorkflowStepDefinition $stepDefinition,
        int $position,
        ?WorkflowStepRun $existingStepRun,
    ): WorkflowStepRun {
        if ($existingStepRun?->status() === WorkflowStepRunStatus::Relaunched) {
            $existingStepRun->markRunning();
            $this->entityManager->flush();

            return $existingStepRun;
        }

        if ($existingStepRun?->status() === WorkflowStepRunStatus::Retrying) {
            $existingStepRun->markRunning();
            $this->entityManager->flush();

            return $existingStepRun;
        }

        $stepRun = new WorkflowStepRun(
            workflowRun: $workflowRun,
            stepType: $stepDefinition->type(),
            stepName: $stepDefinition->code(),
            position: $position,
        );
        $stepRun->markRunning();

        $this->entityManager->persist($stepRun);
        $this->entityManager->flush();

        return $stepRun;
    }

    private function dependenciesAreSatisfied(WorkflowRun $workflowRun, WorkflowStepDefinition $stepDefinition): bool
    {
        if ($stepDefinition->dependsOn() === []) {
            return true;
        }

        $completedDependencies = $this->workflowStepRunRepository->findCompletedByWorkflowRunAndStepNames(
            $workflowRun,
            $stepDefinition->dependsOn(),
        );

        foreach ($stepDefinition->dependsOn() as $dependencyCode) {
            if (!isset($completedDependencies[$dependencyCode])) {
                return false;
            }
        }

        return true;
    }

    private function buildStepInput(WorkflowRun $workflowRun, string $stepCode): WorkflowStepInput
    {
        $payloads = $this->workflowPayloadRepository->findByWorkflowRunAndTargetStepNameOrdered($workflowRun, $stepCode);

        $inputs = [];

        foreach ($payloads as $payload) {
            $snapshot = $this->workflowPayloadStore->load($payload);

            $inputs[] = new WorkflowStepInputPayload(
                sourceStepCode: $payload->sourceStepRun()->stepName(),
                targetStepCode: $payload->targetStepName(),
                records: $this->extractRecordsFromSnapshot($snapshot),
                metadata: $this->extractStepMetadataFromSnapshot($snapshot),
                snapshot: $snapshot,
            );
        }

        return new WorkflowStepInput($inputs);
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return list<array<string, mixed>>
     */
    private function extractRecordsFromSnapshot(array $snapshot): array
    {
        $records = $snapshot['records'] ?? [];

        return is_array($records) ? $records : [];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function extractStepMetadataFromSnapshot(array $snapshot): array
    {
        $metadata = $snapshot['metadata'] ?? [];

        if (!is_array($metadata)) {
            return [];
        }

        $stepMetadata = $metadata['step_metadata'] ?? $metadata;

        return is_array($stepMetadata) ? $stepMetadata : [];
    }

    private function computeDurationMs(int $stepStartedAt): int
    {
        return max(0, (int) floor((hrtime(true) - $stepStartedAt) / 1_000_000));
    }

    private function measurePeakMemoryBytes(): int
    {
        return max(memory_get_peak_usage(true), memory_get_usage(true));
    }

    /**
     * @return list<array{code: string, type: string}>
     */
    private function collectRunnableDownstreamSteps(
        WorkflowRun $workflowRun,
        WorkflowDefinition $definition,
        string $stepCode,
    ): array {
        if ($this->cancellationSynchronizer->synchronizeIfNeeded($workflowRun)) {
            return [];
        }

        $runnable = [];
        $allowedStepCodes = array_flip($this->completionStepCodes($workflowRun, $definition));

        foreach ($definition->downstreamSteps($stepCode) as $downstreamStep) {
            if (!isset($allowedStepCodes[$downstreamStep->code()])) {
                continue;
            }

            if ($this->dependenciesAreSatisfied($workflowRun, $downstreamStep)) {
                $runnable[] = [
                    'code' => $downstreamStep->code(),
                    'type' => $downstreamStep->type(),
                ];
            }
        }

        return $runnable;
    }

    /**
     * @param array<string, mixed>|null $errorPayload
     */
    private function finalizeWorkflowRunState(
        WorkflowRun $workflowRun,
        WorkflowDefinition $definition,
        ?string $errorMessage = null,
        ?array $errorPayload = null,
    ): void {
        if ($this->cancellationSynchronizer->synchronizeIfNeeded($workflowRun)) {
            return;
        }

        $latestStepRunsByCode = $this->latestStepRunsByCode($workflowRun);
        $decision = $this->workflowRunCompletionDecider->decide(
            $definition,
            $latestStepRunsByCode,
            $this->completionStepCodes($workflowRun, $definition),
        );

        foreach ($latestStepRunsByCode as $stepRun) {
            if ($stepRun->status() === WorkflowStepRunStatus::Retrying) {
                $workflowRun->markRetrying();

                return;
            }
        }

        if ($decision === null) {
            $workflowRun->markRunning();

            return;
        }

        if ($decision === WorkflowRunStatus::Completed) {
            $workflowRun->markCompleted();
            $this->workflowExecutionLockManager->releaseForRun($workflowRun, 'completed');

            return;
        }

        $resolvedFailure = $this->resolveFailureDetails($latestStepRunsByCode);
        $message = $errorMessage ?? $resolvedFailure['message'];
        $payload = $errorPayload ?? $resolvedFailure['payload'];

        if ($decision === WorkflowRunStatus::PartiallyFailed) {
            $workflowRun->markPartiallyFailed($message, errorPayload: $payload);
            $this->workflowExecutionLockManager->releaseForRun($workflowRun, 'partially_failed');

            return;
        }

        $workflowRun->markFailed($message, errorPayload: $payload);
        $this->workflowExecutionLockManager->releaseForRun($workflowRun, 'failed');
    }

    /**
     * @return array<string, WorkflowStepRun>
     */
    private function latestStepRunsByCode(WorkflowRun $workflowRun): array
    {
        $latest = [];

        foreach ($this->workflowStepRunRepository->findByWorkflowRunOrdered($workflowRun) as $stepRun) {
            $latest[$stepRun->stepName()] = $stepRun;
        }

        return $latest;
    }

    /**
     * @param array<string, WorkflowStepRun> $latestStepRunsByCode
     * @return array{message: ?string, payload: ?array}
     */
    private function resolveFailureDetails(array $latestStepRunsByCode): array
    {
        foreach ($latestStepRunsByCode as $stepRun) {
            if ($stepRun->status() !== WorkflowStepRunStatus::Failed) {
                continue;
            }

            return [
                'message' => $stepRun->errorMessage(),
                'payload' => $stepRun->errorPayload(),
            ];
        }

        return [
            'message' => null,
            'payload' => null,
        ];
    }

    /**
     * @return list<string>
     */
    private function completionStepCodes(WorkflowRun $workflowRun, WorkflowDefinition $definition): array
    {
        $targetStepCodes = $workflowRun->relaunchMetadata()['target_step_codes'] ?? null;

        if (!is_array($targetStepCodes) || $targetStepCodes === []) {
            return array_map(
                static fn (WorkflowStepDefinition $stepDefinition): string => $stepDefinition->code(),
                $definition->steps(),
            );
        }

        return array_values(array_filter(
            $targetStepCodes,
            static fn (mixed $stepCode): bool => is_string($stepCode) && $stepCode !== '',
        ));
    }
}
