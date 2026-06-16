<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Error;

enum WorkflowErrorCategory: string
{
    case Technical = 'technical';
    case Business = 'business';
}
