<?php

declare(strict_types=1);

namespace Fluxx\Ui;

final readonly class WorkflowStepStatisticsView
{
    public function __construct(
        private string $code,
        private string $name,
        private ?int $averageDurationMs,
        private int $failureCount,
        private int $retryCount,
        private int $idempotenceHitCount,
        private int $executionCount,
    ) {
    }

    public function code(): string
    {
        return $this->code;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function averageDurationMs(): ?int
    {
        return $this->averageDurationMs;
    }

    public function failureCount(): int
    {
        return $this->failureCount;
    }

    public function retryCount(): int
    {
        return $this->retryCount;
    }

    public function idempotenceHitCount(): int
    {
        return $this->idempotenceHitCount;
    }

    public function executionCount(): int
    {
        return $this->executionCount;
    }
}
