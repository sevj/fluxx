<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Relaunch;

enum WorkflowRelaunchMode: string
{
    case Full = 'full';
    case Step = 'step';
    case Branch = 'branch';
}
