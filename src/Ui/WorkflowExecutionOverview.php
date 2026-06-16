<?php

declare(strict_types=1);

namespace Fluxx\Ui;

use DateTimeImmutable;

final readonly class WorkflowExecutionOverview
{
    /**
     * @param list<WorkflowExecutionStepOverview> $steps
     * @param array<string, WorkflowExecutionStepOverview> $stepMap
     */
    public function __construct(
        private string $runId,
        private string $trigger,
        private string $status,
        private ?string $lockKey,
        private ?string $lockScope,
        private ?string $relaunchMode,
        private ?string $originalRunId,
        private ?string $restartStepCode,
        private DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $startedAt,
        private ?DateTimeImmutable $finishedAt,
        private ?string $errorMessage,
        private ?string $errorCategory,
        private array $steps,
        private array $stepMap,
    ) {
    }

    public function runId(): string
    {
        return $this->runId;
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

    public function relaunchMode(): ?string
    {
        return $this->relaunchMode;
    }

    public function originalRunId(): ?string
    {
        return $this->originalRunId;
    }

    public function restartStepCode(): ?string
    {
        return $this->restartStepCode;
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

    public function errorCategory(): ?string
    {
        return $this->errorCategory;
    }

    /**
     * @return list<WorkflowExecutionStepOverview>
     */
    public function steps(): array
    {
        return $this->steps;
    }

    /**
     * @return array<string, WorkflowExecutionStepOverview>
     */
    public function stepMap(): array
    {
        return $this->stepMap;
    }
}
