<?php

declare(strict_types=1);

namespace Fluxx\Entity\Enum;

enum WorkflowStepDeduplicationStatus: string
{
    case None = 'none';
    case Applied = 'applied';
    case Deduplicated = 'deduplicated';
}
