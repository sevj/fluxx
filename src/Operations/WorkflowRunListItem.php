<?php

declare(strict_types=1);

namespace Fluxx\Operations;

use DateTimeImmutable;

final readonly class WorkflowRunListItem
{
    public function __construct(
        private string $runId,
        private string $workflowCode,
        private string $trigger,
        private string $status,
        private string $sourceSystem,
        private string $targetSystem,
        private DateTimeImmutable $createdAt,
        private ?string $errorMessage,
    ) {
    }

    public function runId(): string
    {
        return $this->runId;
    }

    public function workflowCode(): string
    {
        return $this->workflowCode;
    }

    public function trigger(): string
    {
        return $this->trigger;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function sourceSystem(): string
    {
        return $this->sourceSystem;
    }

    public function targetSystem(): string
    {
        return $this->targetSystem;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function errorMessage(): ?string
    {
        return $this->errorMessage;
    }
}
