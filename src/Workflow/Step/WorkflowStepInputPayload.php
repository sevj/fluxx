<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Step;

final readonly class WorkflowStepInputPayload
{
    /**
     * @param list<array<string, mixed>> $records
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $snapshot
     */
    public function __construct(
        private string $sourceStepCode,
        private string $targetStepCode,
        private array $records,
        private array $metadata,
        private array $snapshot,
    ) {
    }

    public function sourceStepCode(): string
    {
        return $this->sourceStepCode;
    }

    public function targetStepCode(): string
    {
        return $this->targetStepCode;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function records(): array
    {
        return $this->records;
    }

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
