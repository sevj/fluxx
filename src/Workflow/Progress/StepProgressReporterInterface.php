<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Progress;

/**
 * Lets step handlers publish their progress while they run, so it can be
 * followed live whatever process executes the step. Calls made outside of a
 * running step are ignored, and a reporting failure never fails the step.
 */
interface StepProgressReporterInterface
{
    /**
     * @param int    $done  units of work already handled in the current phase
     * @param int    $total units of work of the current phase
     * @param string $label optional name of the current phase, shown as is
     */
    public function report(int $done, int $total, string $label = ''): void;
}
