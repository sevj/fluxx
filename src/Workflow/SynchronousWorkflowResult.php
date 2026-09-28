<?php

declare(strict_types=1);

namespace Fluxx\Workflow;

final readonly class SynchronousWorkflowResult
{
    /**
     * @param list<array<string, mixed>> $records
     */
    public function __construct(
        public string $runId,
        public array $records,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function records(): array
    {
        return $this->records;
    }

    public function runId(): string
    {
        return $this->runId;
    }
}
