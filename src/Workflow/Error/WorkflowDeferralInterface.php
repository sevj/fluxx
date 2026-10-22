<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Error;

use DateTimeImmutable;

interface WorkflowDeferralInterface
{
    public function deferredUntil(): DateTimeImmutable;
}
