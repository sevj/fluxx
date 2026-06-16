<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Step;

use Fluxx\Workflow\Context\WorkflowContext;
use Fluxx\Workflow\Result\WorkflowStepResult;

interface ExecutableWorkflowStepInterface extends WorkflowStepInterface
{
    public function execute(WorkflowContext $context, WorkflowStepInput $input): WorkflowStepResult;
}
