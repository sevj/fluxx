<?php

declare(strict_types=1);

namespace Fluxx\Ui;

use DateTimeImmutable;

final readonly class RunRowView
{
    public function __construct(
        private string $runId,
        private string $workflowCode,
        private ?string $workflowName,
        private ?string $workflowCategory,
        private string $sourceSystem,
        private string $targetSystem,
        private string $trigger,
        private string $status,
        private ?string $lockKey,
        private ?string $lockScope,
        private DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $startedAt,
        private ?DateTimeImmutable $finishedAt,
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

    public function workflowName(): ?string
    {
        return $this->workflowName;
    }

    public function workflowCategory(): ?string
    {
        return $this->workflowCategory;
    }

    public function sourceSystem(): string
    {
        return $this->sourceSystem;
    }

    public function targetSystem(): string
    {
        return $this->targetSystem;
    }

    public function trigger(): string
    {
        return $this->trigger;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function lockKey(): ?string
    {
        return $this->lockKey;
    }

    public function lockScope(): ?string
    {
        return $this->lockScope;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function startedAt(): ?DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function finishedAt(): ?DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function errorMessage(): ?string
    {
        return $this->errorMessage;
    }
}
