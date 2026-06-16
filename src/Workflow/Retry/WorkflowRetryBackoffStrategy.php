<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Retry;

enum WorkflowRetryBackoffStrategy: string
{
    case Fixed = 'fixed';
    case Linear = 'linear';
    case Exponential = 'exponential';
}
