<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Step;

final readonly class WorkflowStepIdempotence
{
    public function __construct(
        private string $strategy = 'step_input_key',
    ) {
    }

    public function strategy(): string
    {
        return $this->strategy;
    }
}
