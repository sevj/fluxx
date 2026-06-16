<?php

declare(strict_types=1);

namespace Fluxx\Tests\Fixture;

use Fluxx\Workflow\Context\WorkflowContext;
use Fluxx\Workflow\Result\WorkflowStepResult;
use Fluxx\Workflow\Step\ExecutableWorkflowStepInterface;
use Fluxx\Workflow\Step\WorkflowStepInput;

final class StubExecutableStep implements ExecutableWorkflowStepInterface
{
    public function __construct(
        private readonly string $code,
        private readonly string $name,
        private readonly ?WorkflowStepResult $result = null,
    ) {
    }

    public function code(): string
    {
        return $this->code;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function execute(WorkflowContext $context, WorkflowStepInput $input): WorkflowStepResult
    {
        return $this->result ?? new WorkflowStepResult();
    }
}
