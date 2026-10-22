<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Error;

use DateTimeImmutable;
use RuntimeException;
use Throwable;

class DeferredWorkflowException extends RuntimeException implements WorkflowErrorInterface, WorkflowDeferralInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $message,
        private readonly DateTimeImmutable $deferredUntil,
        private readonly ?string $workflowErrorCode = 'deferred',
        private readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function deferredUntil(): DateTimeImmutable
    {
        return $this->deferredUntil;
    }

    public function workflowErrorCategory(): WorkflowErrorCategory
    {
        return WorkflowErrorCategory::Technical;
    }

    public function workflowErrorCode(): ?string
    {
        return $this->workflowErrorCode;
    }

    public function workflowErrorContext(): array
    {
        return $this->context;
    }
}
