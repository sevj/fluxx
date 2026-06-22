<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Step;

interface WorkflowStepInterface
{
    public function code(): string;
    public static function staticCode(): string;

    public function name(): string;
}
