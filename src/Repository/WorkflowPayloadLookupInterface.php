<?php

declare(strict_types=1);

namespace Fluxx\Repository;

use Fluxx\Entity\WorkflowPayload;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;

interface WorkflowPayloadLookupInterface
{
    /**
     * @return list<WorkflowPayload>
     */
    public function findBySourceStepRunOrdered(WorkflowStepRun $stepRun): array;

    /**
     * @return list<WorkflowPayload>
     */
    public function findByWorkflowRunAndTargetStepNameOrdered(WorkflowRun $workflowRun, string $stepName): array;
}
