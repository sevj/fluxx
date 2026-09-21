<?php

declare(strict_types=1);

namespace Fluxx\Entity\Enum;

enum WorkflowStepType: string
{
    case Read = 'read';
    case Splitter = 'splitter';
    case Transform = 'transform';
    case Write = 'write';
    case Linker = 'linker';
}
