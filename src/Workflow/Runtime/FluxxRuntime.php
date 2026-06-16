<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Runtime;

use Doctrine\ORM\EntityManagerInterface;
use Fluxx\Entity\Enum\WorkflowRunStatus;
use Fluxx\Entity\WorkflowPayload;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;
use Fluxx\Entity\Enum\WorkflowStepRunStatus;
use Fluxx\Repository\WorkflowPayloadRepository;
use Fluxx\Repository\WorkflowRunRepository;
use Fluxx\Repository\WorkflowStepRunRepository;
use Fluxx\Workflow\Context\WorkflowContext;
use Fluxx\Workflow\Context\WorkflowContextFactory;
use Fluxx\Workflow\Error\WorkflowErrorPayloadFactory;
use Fluxx\Workflow\Lock\WorkflowExecutionLockManager;
use Fluxx\Workflow\Payload\WorkflowPayloadStore;
use Fluxx\Workflow\Retry\WorkflowRetryPolicy;
use Fluxx\Workflow\Step\IdempotentWorkflowStepInterface;
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
        private WorkflowRunRepository $workflowRunRepository,
        private WorkflowStepRunRepository $workflowStepRunRepository,
        private WorkflowPayloadRepository $workflowPayloadRepository,
        private WorkflowPayloadStore $workflowPayloadStore,
        private WorkflowContextFactory $workflowContextFactory,
        private WorkflowExecutionLockManager $workflowExecutionLockManager,
        private WorkflowErrorPayloadFactory $workflowErrorPayloadFactory,
        private WorkflowRunCompletionDecider $workflowRunCompletionDecider,
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
        ], true)) {
            return [];
        }

        $workflow = $this->registry->get($workflowRun->workflowName());
        $definition = $workflow->definition();
        $stepDefinition = $definition->step($stepCode);

        $existingStepRun = $this->workflowStepRunRepository->findLatestByWorkflowRunAndStepName($workflowRun, $stepCode);
        if ($existingStepRun?->status()->value === 'completed') {
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

        try {
            if ($this->tryCompleteFromIdempotenceHit($workflowRun, $definition, $stepDefinition, $stepRun, $context, $input, $stepStartedAt)) {
                return $this->collectRunnableDownstreamSteps($workflowRun, $definition, $stepCode);
            }

            $result = $stepDefinition->handler()->execute(
                $context,
                $input,
            );

            $stepRun->replaceMetadata($result->metadata());
            $this->applyIdempotenceKey($stepDefinition, $stepRun, $context, $input);

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

            $this->finalizeWorkflowRunState($workflowRun, $definition);

            $this->entityManager->flush();

            return $this->collectRunnableDownstreamSteps($workflowRun, $definition, $stepCode);
        } catch (Throwable $throwable) {
            $errorPayload = $this->workflowErrorPayloadFactory->fromThrowable($throwable);
            $retryPolicy = $this->resolveRetryPolicy($definition, $stepDefinition);
            $durationMs = $this->computeDurationMs($stepStartedAt);
            $memoryPeakBytes = $this->measurePeakMemoryBytes();

            if ($this->scheduleRetryIfNeeded($workflowRun, $stepRun, $retryPolicy, $throwable->getMessage(), $errorPayload, $durationMs, $memoryPeakBytes)) {
                $this->entityManager->flush();

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

            throw $throwable;
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

        return new WorkflowStepInput(array_map(
            fn (WorkflowPayload $payload): WorkflowStepInputPayload => new WorkflowStepInputPayload(
                sourceStepCode: $payload->sourceStepRun()->stepName(),
                targetStepCode: $payload->targetStepName(),
                records: $this->extractRecords($payload),
                metadata: $this->extractStepMetadata($payload),
                snapshot: $this->workflowPayloadStore->load($payload),
            ),
            $payloads,
        ));
    }

    private function applyIdempotenceKey(
        WorkflowStepDefinition $stepDefinition,
        WorkflowStepRun $stepRun,
        WorkflowContext $context,
        WorkflowStepInput $input,
    ): ?string {
        $idempotenceKey = $this->resolveIdempotenceKey($stepDefinition, $context, $input);

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

    /**
     * @return list<array<string, mixed>>
     */
    private function extractRecords(WorkflowPayload $payload): array
    {
        $snapshot = $this->workflowPayloadStore->load($payload);
        $records = $snapshot['records'] ?? [];

        return is_array($records) ? $records : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractStepMetadata(WorkflowPayload $payload): array
    {
        $snapshot = $this->workflowPayloadStore->load($payload);
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

    private function resolveIdempotenceKey(
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

    private function tryCompleteFromIdempotenceHit(
        WorkflowRun $workflowRun,
        WorkflowDefinition $definition,
        WorkflowStepDefinition $stepDefinition,
        WorkflowStepRun $stepRun,
        WorkflowContext $context,
        WorkflowStepInput $input,
        int $stepStartedAt,
    ): bool {
        $idempotenceKey = $this->resolveIdempotenceKey($stepDefinition, $context, $input);

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

        $this->finalizeWorkflowRunState($workflowRun, $definition);

        $this->entityManager->flush();

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

    /**
     * @return list<array{code: string, type: string}>
     */
    private function collectRunnableDownstreamSteps(
        WorkflowRun $workflowRun,
        WorkflowDefinition $definition,
        string $stepCode,
    ): array {
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

    private function resolveRetryPolicy(
        WorkflowDefinition $definition,
        WorkflowStepDefinition $stepDefinition,
    ): ?WorkflowRetryPolicy {
        return $stepDefinition->retryPolicy() ?? $definition->retryPolicy();
    }

    /**
     * @param array<string, mixed>|null $errorPayload
     */
    private function scheduleRetryIfNeeded(
        WorkflowRun $workflowRun,
        WorkflowStepRun $stepRun,
        ?WorkflowRetryPolicy $retryPolicy,
        ?string $errorMessage,
        ?array $errorPayload,
        ?int $durationMs,
        ?int $memoryPeakBytes,
    ): bool {
        if ($retryPolicy === null) {
            return false;
        }

        if (($errorPayload['category'] ?? null) !== 'technical') {
            return false;
        }

        if ($stepRun->retryCount() >= $retryPolicy->maxRetries()) {
            return false;
        }

        $attempt = $stepRun->retryCount() + 1;
        $delayMilliseconds = $retryPolicy->delayMillisecondsForAttempt($attempt);
        $retryScheduledAt = new \DateTimeImmutable();
        $nextRetryAt = $retryScheduledAt->modify(sprintf('+%d seconds', (int) ceil($delayMilliseconds / 1000)));

        if (!$nextRetryAt instanceof \DateTimeImmutable) {
            return false;
        }

        $stepRun->scheduleRetry(
            lastRetryAt: $retryScheduledAt,
            nextRetryAt: $nextRetryAt,
            errorMessage: $errorMessage,
            errorCount: 1,
            durationMs: $durationMs,
            memoryPeakBytes: $memoryPeakBytes,
            errorPayload: $errorPayload,
        );

        $metadata = $stepRun->metadata();
        $metadata['retry'] = [
            'count' => $stepRun->retryCount(),
            'max_retries' => $retryPolicy->maxRetries(),
            'delay_seconds' => $retryPolicy->delaySeconds(),
            'backoff_strategy' => $retryPolicy->backoffStrategy()->value,
            'last_retry_at' => $retryScheduledAt->format(DATE_ATOM),
            'next_retry_at' => $nextRetryAt->format(DATE_ATOM),
        ];
        $stepRun->replaceMetadata($metadata);

        $workflowRun->markRetrying();

        StepMessageDispatcher::dispatch(
            $this->messageBus,
            $workflowRun->runId(),
            $stepRun->stepName(),
            $delayMilliseconds,
        );

        return true;
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
