<?php

declare(strict_types=1);

namespace Fluxx\Ui;

use DateTimeImmutable;

final readonly class WorkflowOverview
{
    public function __construct(
        private string $code,
        private string $name,
        private string $sourceSystem,
        private string $targetSystem,
        private ?DateTimeImmutable $lastExecutionAt,
        private int $executionCount,
        private int $errorCount,
        private ?DateTimeImmutable $lastErrorAt,
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

    public function sourceSystem(): string
    {
        return $this->sourceSystem;
    }

    public function targetSystem(): string
    {
        return $this->targetSystem;
    }

    public function lastExecutionAt(): ?DateTimeImmutable
    {
        return $this->lastExecutionAt;
    }

    public function executionCount(): int
    {
        return $this->executionCount;
    }

    public function errorCount(): int
    {
        return $this->errorCount;
    }

    public function lastErrorAt(): ?DateTimeImmutable
    {
        return $this->lastErrorAt;
    }
}
