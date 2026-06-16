<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Context;

final readonly class WorkflowContext
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private string $workflowCode,
        private string $workflowName,
        private string $sourceSystem,
        private string $targetSystem,
        private string $runId,
        private string $trigger = 'manual',
        private ?string $batchId = null,
        private array $metadata = [],
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

    public function batchId(): ?string
    {
        return $this->batchId;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }
}
