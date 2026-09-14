<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Relaunch;

use Fluxx\Entity\Enum\WorkflowRunStatus;
use RuntimeException;

/**
 * Raised when a relaunch is requested for a run that is not in a terminal
 * state. Relaunching a run still in flight would race with its in-progress
 * execution and produce overlapping work for the same workflow/source.
 *
 * Operators can opt out of the guard by acknowledging the risk explicitly
 * (e.g. --force) when the original worker is known to be stuck permanently.
 */
final class RunStillActiveException extends RuntimeException
{
    public static function fromStatus(string $runId, WorkflowRunStatus $status): self
    {
        return new self(sprintf(
            'Run "%s" is still in progress (status: "%s"). Relaunch only terminal runs, or force the operation when the original worker is definitely stuck.',
            $runId,
            $status->value,
        ));
    }
}
