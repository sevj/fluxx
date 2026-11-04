<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Lock;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Fluxx\Entity\WorkflowExecutionLock;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Workflow\WorkflowDefinition;
use Throwable;

interface WorkflowExecutionLockManagerInterface
{
    public function acquire(WorkflowRun $workflowRun, WorkflowDefinition $definition): ?WorkflowExecutionLock;

    public function releaseForRun(WorkflowRun $workflowRun, string $reason): void;

    /**
     * Tells what to report when flushing an acquired lock fails: another run claiming
     * the same key at the same moment is a lock conflict, anything else stays as is.
     */
    public function flushFailure(WorkflowRun $workflowRun, UniqueConstraintViolationException $exception): Throwable;
}
