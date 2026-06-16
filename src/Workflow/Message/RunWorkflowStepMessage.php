<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Message;

final readonly class RunWorkflowStepMessage
{
    public function __construct(
        private string $runId,
        private string $stepCode,
    ) {
    }

    public function runId(): string
    {
        return $this->runId;
    }

    public function stepCode(): string
    {
        return $this->stepCode;
    }
}
