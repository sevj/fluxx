<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Check;

enum CheckOutcome: string
{
    case Ok = 'ok';
    case Deviation = 'deviation';
}
