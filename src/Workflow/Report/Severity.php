<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Report;

enum Severity: string
{
    case Blocking = 'blocking';
    case Warning = 'warning';
}
