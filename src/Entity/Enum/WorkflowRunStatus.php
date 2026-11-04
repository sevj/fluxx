<?php

declare(strict_types=1);

namespace Fluxx\Entity\Enum;

enum WorkflowRunStatus: string
{
    case Pending = 'pending';
    case Relaunched = 'relaunched';
    case Retrying = 'retrying';
    case Running = 'running';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
    case Failed = 'failed';
    case PartiallyFailed = 'partially_failed';
    case PayloadsPruned = 'payloads_pruned';

    /**
     * Whether a run in this status can still change on its own.
     */
    public function isActive(): bool
    {
        return in_array($this, [self::Pending, self::Relaunched, self::Retrying, self::Running], true);
    }

    /**
     * Whether a run in this status can be relaunched without forcing it: it has
     * finished, and its payloads are still available.
     */
    public function isRelaunchable(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::PartiallyFailed, self::Cancelled], true);
    }
}
