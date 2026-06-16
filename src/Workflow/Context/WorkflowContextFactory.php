<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Context;

use Fluxx\Entity\WorkflowRun;
use Fluxx\Workflow\WorkflowDefinition;

final readonly class WorkflowContextFactory
{
    public function createFromRun(WorkflowRun $workflowRun, WorkflowDefinition $workflowDefinition): WorkflowContext
    {
        return new WorkflowContext(
            workflowCode: $workflowRun->workflowName(),
            workflowName: $workflowDefinition->name(),
            sourceSystem: $workflowRun->sourceSystem(),
            targetSystem: $workflowRun->targetSystem(),
            runId: $workflowRun->runId(),
            trigger: $workflowRun->trigger(),
            batchId: $workflowRun->batchId(),
            metadata: $workflowRun->metadata(),
        );
    }
}
