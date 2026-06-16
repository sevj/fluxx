<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Result;

final readonly class WorkflowStepResult
{
    /**
     * @param list<array<string, mixed>> $records
     * @param array<string, mixed> $metadata
     * @param array<string, WorkflowStepOutput> $branchOutputs
     */
    public function __construct(
        private array $records = [],
        private array $metadata = [],
        private ?int $processedCount = null,
        private ?int $successCount = null,
        private int $errorCount = 0,
        private array $branchOutputs = [],
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

    public function processedCount(): int
    {
        return $this->processedCount ?? count($this->records);
    }

    public function successCount(): int
    {
        return $this->successCount ?? max($this->processedCount() - $this->errorCount, 0);
    }

    public function errorCount(): int
    {
        return $this->errorCount;
    }

    /**
     * @return array<string, WorkflowStepOutput>
     */
    public function branchOutputs(): array
    {
        return $this->branchOutputs;
    }

    public function outputFor(string $targetStepCode): WorkflowStepOutput
    {
        if (isset($this->branchOutputs[$targetStepCode])) {
            return $this->branchOutputs[$targetStepCode];
        }

        return new WorkflowStepOutput(
            records: $this->records,
            metadata: $this->metadata,
        );
    }
}
