<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Runtime;

use DateTimeImmutable;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;
use Fluxx\Settings\RuntimeSettingsManager;
use Fluxx\Workflow\Error\WorkflowErrorCategory;
use Fluxx\Workflow\MessageHandler\StepMessageDispatcher;
use Fluxx\Workflow\Retry\WorkflowRetryPolicy;
use Fluxx\Workflow\WorkflowDefinition;
use Fluxx\Workflow\WorkflowStepDefinition;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class WorkflowRetryScheduler
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private RuntimeSettingsManager $runtimeSettingsManager,
    ) {
    }

    public function resolvePolicy(
        WorkflowDefinition $definition,
        WorkflowStepDefinition $stepDefinition,
    ): ?WorkflowRetryPolicy {
        return $stepDefinition->retryPolicy() ?? $definition->retryPolicy();
    }

    /**
     * @param array<string, mixed>|null $errorPayload
     */
    public function scheduleIfNeeded(
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

        if (($errorPayload['category'] ?? null) !== WorkflowErrorCategory::Technical->value) {
            return false;
        }

        $globalMaxRetries = $this->runtimeSettingsManager->get()->maxGlobalRetries;
        $effectiveMaxRetries = min($retryPolicy->maxRetries(), max(0, $globalMaxRetries));

        if ($stepRun->retryCount() >= $effectiveMaxRetries) {
            return false;
        }

        $attempt = $stepRun->retryCount() + 1;
        $delayMilliseconds = $retryPolicy->delayMillisecondsForAttempt($attempt);
        $retryScheduledAt = new DateTimeImmutable();
        $nextRetryAt = $retryScheduledAt->modify(sprintf('+%d seconds', (int) ceil($delayMilliseconds / 1000)));

        if (!$nextRetryAt instanceof DateTimeImmutable) {
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
            'global_max_retries' => $globalMaxRetries,
            'effective_max_retries' => $effectiveMaxRetries,
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
}
