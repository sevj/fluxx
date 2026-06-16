<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Lock;

use Fluxx\Entity\WorkflowExecutionLock;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Workflow\WorkflowDefinition;

interface WorkflowExecutionLockManagerInterface
{
    public function acquire(WorkflowRun $workflowRun, WorkflowDefinition $definition): ?WorkflowExecutionLock;

    public function releaseForRun(WorkflowRun $workflowRun, string $reason): void;
}
