<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Check;

use Fluxx\Workflow\Step\ExecutableWorkflowStepInterface;

/**
 * Handler of a check step: it only reads the target system and returns a result carrying a {@see CheckReport}.
 * Fluxx decides the step status from it: a blocking deviation fails the step without retry,
 * a warning deviation completes it with warnings. Fluxx cannot enforce that the step stays read-only.
 */
interface CheckStepInterface extends ExecutableWorkflowStepInterface
{
}
