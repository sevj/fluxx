<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Retry;

use InvalidArgumentException;

final readonly class WorkflowRetryPolicy
{
    public function __construct(
        private int $maxRetries = 3,
        private int $delaySeconds = 60,
        private WorkflowRetryBackoffStrategy $backoffStrategy = WorkflowRetryBackoffStrategy::Fixed,
    ) {
        if ($this->maxRetries < 1) {
            throw new InvalidArgumentException('Workflow retry policy max retries must be positive.');
        }

        if ($this->delaySeconds < 1) {
            throw new InvalidArgumentException('Workflow retry policy delay must be positive.');
        }
    }

    public function maxRetries(): int
    {
        return $this->maxRetries;
    }

    public function delaySeconds(): int
    {
        return $this->delaySeconds;
    }

    public function backoffStrategy(): WorkflowRetryBackoffStrategy
    {
        return $this->backoffStrategy;
    }

    public function delayMillisecondsForAttempt(int $attempt): int
    {
        if ($attempt < 1) {
            throw new InvalidArgumentException('Workflow retry attempt must be positive.');
        }

        $baseDelay = $this->delaySeconds * 1000;

        return match ($this->backoffStrategy) {
            WorkflowRetryBackoffStrategy::Fixed => $baseDelay,
            WorkflowRetryBackoffStrategy::Linear => $baseDelay * $attempt,
            WorkflowRetryBackoffStrategy::Exponential => $baseDelay * (2 ** ($attempt - 1)),
        };
    }
}
