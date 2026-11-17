<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Check;

use Fluxx\Workflow\Step\ExecutableWorkflowStepInterface;

/**
 * Handler of a check step: it only reads the target system and returns a result carrying a {@see CheckReport}.
 * A check reports, it never fails the run: any deviation completes the step and the run with warnings,
 * since everything it checks is already done. Fluxx cannot enforce that the step stays read-only.
 */
interface CheckStepInterface extends ExecutableWorkflowStepInterface
{
}
