<?php

declare(strict_types=1);

namespace Fluxx\Entity\Enum;

enum WorkflowStepRunStatus: string
{
    case Pending = 'pending';
    case Relaunched = 'relaunched';
    case Retrying = 'retrying';
    case Running = 'running';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
    case CompletedWithWarnings = 'completed_with_warnings';
    case Failed = 'failed';

    /**
     * Whether the step has finished successfully, with or without warnings:
     * either way its downstream steps can run.
     */
    public function isCompleted(): bool
    {
        return $this === self::Completed || $this === self::CompletedWithWarnings;
    }
}
