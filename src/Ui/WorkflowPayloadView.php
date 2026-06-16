<?php

declare(strict_types=1);

namespace Fluxx\Ui;

use DateTimeImmutable;

final readonly class WorkflowPayloadView
{
    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $snapshot
     */
    public function __construct(
        private int $id,
        private int $sequence,
        private int $recordCount,
        private int $rawSize,
        private int $storedSize,
        private string $format,
        private string $compression,
        private string $storageMode,
        private string $contentHash,
        private DateTimeImmutable $createdAt,
        private array $metadata,
        private array $snapshot,
    ) {
    }

    public function id(): int { return $this->id; }
    public function sequence(): int { return $this->sequence; }
    public function recordCount(): int { return $this->recordCount; }
    public function rawSize(): int { return $this->rawSize; }
    public function storedSize(): int { return $this->storedSize; }
    public function format(): string { return $this->format; }
    public function compression(): string { return $this->compression; }
    public function storageMode(): string { return $this->storageMode; }
    public function contentHash(): string { return $this->contentHash; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return $this->snapshot;
    }
}
