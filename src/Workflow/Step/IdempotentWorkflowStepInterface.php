<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Step;

use Fluxx\Workflow\Context\WorkflowContext;

interface IdempotentWorkflowStepInterface extends ExecutableWorkflowStepInterface
{
    public function idempotenceKey(WorkflowContext $context, WorkflowStepInput $input): ?string;
}
