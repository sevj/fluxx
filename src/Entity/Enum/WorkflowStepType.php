<?php

declare(strict_types=1);

namespace Fluxx\Entity\Enum;

final class WorkflowStepType
{
    public const Read = 'read';
    public const Splitter = 'splitter';
    public const Transform = 'transform';
    public const Write = 'write';
    public const Linker = 'linker';

    private function __construct()
    {
    }
}
