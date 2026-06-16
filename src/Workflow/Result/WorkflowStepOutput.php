<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Result;

final readonly class WorkflowStepOutput
{
    /**
     * @param list<array<string, mixed>> $records
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private array $records = [],
        private array $metadata = [],
        private ?int $recordCount = null,
    ) {
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

    public function recordCount(): int
    {
        return $this->recordCount ?? count($this->records);
    }
}
