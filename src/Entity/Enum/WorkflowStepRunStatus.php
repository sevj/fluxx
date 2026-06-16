<?php

declare(strict_types=1);

namespace Fluxx\Entity\Enum;

enum WorkflowStepRunStatus: string
{
    case Pending = 'pending';
    case Relaunched = 'relaunched';
    case Retrying = 'retrying';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
