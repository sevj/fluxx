<?php

declare(strict_types=1);

namespace Fluxx\Tests\Fixture;

use Fluxx\Workflow\Context\WorkflowContext;
use Fluxx\Workflow\Result\WorkflowStepResult;
use Fluxx\Workflow\Step\IdempotentWorkflowStepInterface;
use Fluxx\Workflow\Step\WorkflowStepInput;

final class StubIdempotentStep implements IdempotentWorkflowStepInterface
{
    public function __construct(
        private readonly string $idempotenceKey,
        private readonly ?WorkflowStepResult $result = null,
    ) {
    }

    public function code(): string
    {
        return 'fetch';
    }

    public function name(): string
    {
        return 'Fetch';
    }

    public static function staticCode(): string
    {
        return 'fetch';
    }

    public function execute(WorkflowContext $context, WorkflowStepInput $input): WorkflowStepResult
    {
        return $this->result ?? new WorkflowStepResult();
    }

    public function idempotenceKey(WorkflowContext $context, WorkflowStepInput $input): ?string
    {
        return $this->idempotenceKey;
    }
}
