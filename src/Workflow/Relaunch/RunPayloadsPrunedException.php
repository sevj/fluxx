<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Relaunch;

use Fluxx\Entity\Enum\WorkflowRunStatus;
use RuntimeException;

final class RunPayloadsPrunedException extends RuntimeException
{
    public static function forRun(string $runId): self
    {
        return new self(sprintf(
            'Run "%s" had its payloads pruned and cannot be relaunched. Use a fresh run, or relaunch from full mode with fresh inputs.',
            $runId,
        ));
    }

    public static function isPruned(WorkflowRunStatus $status): bool
    {
        return $status === WorkflowRunStatus::PayloadsPruned;
    }
}
