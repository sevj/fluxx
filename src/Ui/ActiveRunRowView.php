<?php

declare(strict_types=1);

namespace Fluxx\Ui;

use DateTimeImmutable;

final readonly class ActiveRunRowView
{
    public function __construct(
        private string $runId,
        private string $workflowCode,
        private ?string $workflowName,
        private string $sourceSystem,
        private string $targetSystem,
        private string $trigger,
        private string $status,
        private DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $startedAt,
        private string $durationLabel,
        private int $totalSteps,
        private int $completedSteps,
        private int $failedSteps,
        private float $progressPercent,
        private array $currentSteps,
        private array $workerNames,
        private bool $isOrphaned,
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

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function startedAt(): ?DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function durationLabel(): string
    {
        return $this->durationLabel;
    }

    public function totalSteps(): int
    {
        return $this->totalSteps;
    }

    public function completedSteps(): int
    {
        return $this->completedSteps;
    }

    public function failedSteps(): int
    {
        return $this->failedSteps;
    }

    public function progressPercent(): float
    {
        return $this->progressPercent;
    }

    /**
     * @return list<ActiveRunStepView>
     */
    public function currentSteps(): array
    {
        return $this->currentSteps;
    }

    /**
     * @return list<string>
     */
    public function workerNames(): array
    {
        return $this->workerNames;
    }

    public function isOrphaned(): bool
    {
        return $this->isOrphaned;
    }
}
