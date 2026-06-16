<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Error;

use RuntimeException;
use Throwable;

class BusinessWorkflowException extends RuntimeException implements WorkflowErrorInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $message,
        private readonly ?string $workflowErrorCode = null,
        private readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function workflowErrorCategory(): WorkflowErrorCategory
    {
        return WorkflowErrorCategory::Business;
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
