<?php

declare(strict_types=1);

namespace Fluxx\Ui;

use DateTimeImmutable;

final readonly class TroubleshootingIssueView
{
    public function __construct(
        private string $workflowCode,
        private string $workflowName,
        private string $sourceSystem,
        private string $targetSystem,
        private string $stepCode,
        private string $stepName,
        private string $runId,
        private ?DateTimeImmutable $failedAt,
        private string $errorMessage,
        private int $failureCount,
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

    public function stepCode(): string
    {
        return $this->stepCode;
    }

    public function stepName(): string
    {
        return $this->stepName;
    }

    public function runId(): string
    {
        return $this->runId;
    }

    public function failedAt(): ?DateTimeImmutable
    {
        return $this->failedAt;
    }

    public function errorMessage(): string
    {
        return $this->errorMessage;
    }

    public function failureCount(): int
    {
        return $this->failureCount;
    }
}
