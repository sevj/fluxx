<?php

declare(strict_types=1);

namespace Fluxx\Ui;

final readonly class WorkflowExecutionStepOverview
{
    public function __construct(
        private string $type,
        private string $typeLabel,
        private string $typeTone,
        private ?string $typeToneStyle,
        private string $code,
        private string $name,
        private string $status,
        private int $processedCount,
        private int $successCount,
        private int $errorCount,
        private ?int $durationMs,
        private ?int $memoryPeakBytes,
        private ?string $idempotenceKey,
        private string $deduplicationStatus,
        private ?string $deduplicatedFromRunId,
    ) {
    }

    public function type(): string
    {
        return $this->type;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function typeLabel(): string
    {
        return $this->typeLabel;
    }

    public function typeTone(): string
    {
        return $this->typeTone;
    }

    public function typeToneStyle(): ?string
    {
        return $this->typeToneStyle;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function processedCount(): int
    {
        return $this->processedCount;
    }

    public function successCount(): int
    {
        return $this->successCount;
    }

    public function errorCount(): int
    {
        return $this->errorCount;
    }

    public function durationMs(): ?int
    {
        return $this->durationMs;
    }

    public function memoryPeakBytes(): ?int
    {
        return $this->memoryPeakBytes;
    }

    public function idempotenceKey(): ?string
    {
        return $this->idempotenceKey;
    }

    public function deduplicationStatus(): string
    {
        return $this->deduplicationStatus;
    }

    public function deduplicatedFromRunId(): ?string
    {
        return $this->deduplicatedFromRunId;
    }
}
