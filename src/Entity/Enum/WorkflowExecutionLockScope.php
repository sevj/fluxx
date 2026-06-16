<?php

declare(strict_types=1);

namespace Fluxx\Entity\Enum;

enum WorkflowExecutionLockScope: string
{
    case Workflow = 'workflow';
    case WorkflowSource = 'workflow_source';
    case WorkflowSourceTarget = 'workflow_source_target';
    case WorkflowBusinessPartition = 'workflow_business_partition';
}
