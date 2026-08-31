<?php

declare(strict_types=1);

namespace Fluxx\Ui;

use DateTimeImmutable;

final readonly class RunDetailView
{
    /**
     * @param list<WorkflowExecutionStepOverview> $steps
     */
    public function __construct(
        private string $workflowCode,
        private string $workflowName,
        private string $sourceSystem,
        private string $targetSystem,
        private string $runId,
        private string $trigger,
        private string $status,
        private ?string $lockKey,
        private ?string $lockScope,
        private ?string $relaunchMode,
        private ?string $originalRunId,
        private ?string $restartStepCode,
        private ?string $batchId,
        private DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $startedAt,
        private ?DateTimeImmutable $finishedAt,
        private ?string $errorMessage,
        private ?string $errorCategory,
        private int $stepCount,
        private int $processedTotal,
        private int $successTotal,
        private int $errorTotal,
        private array $steps,
    ) {
    }

    public function workflowCode(): string
    {
        return $this->workflowCode;
    }

    public function workflowName(): string
    {
        return $this->workflowName;
    }

    public function sourceSystem(): string
    {
        return $this->sourceSystem;
    }

    public function targetSystem(): string
    {
        return $this->targetSystem;
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

    public function batchId(): ?string
    {
        return $this->batchId;
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

    public function stepCount(): int
    {
        return $this->stepCount;
    }

    public function processedTotal(): int
    {
        return $this->processedTotal;
    }

    public function successTotal(): int
    {
        return $this->successTotal;
    }

    public function errorTotal(): int
    {
        return $this->errorTotal;
    }

    /**
     * @return list<WorkflowExecutionStepOverview>
     */
    public function steps(): array
    {
        return $this->steps;
    }
}
