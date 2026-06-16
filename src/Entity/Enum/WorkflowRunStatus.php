<?php

declare(strict_types=1);

namespace Fluxx\Entity\Enum;

enum WorkflowRunStatus: string
{
    case Pending = 'pending';
    case Relaunched = 'relaunched';
    case Retrying = 'retrying';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case PartiallyFailed = 'partially_failed';
}
