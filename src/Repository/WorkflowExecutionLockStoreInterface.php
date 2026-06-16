<?php

declare(strict_types=1);

namespace Fluxx\Repository;

use Fluxx\Entity\WorkflowExecutionLock;

interface WorkflowExecutionLockStoreInterface
{
    public function findActiveByLockKey(string $lockKey): ?WorkflowExecutionLock;

    public function findActiveByOwnerRunId(string $ownerRunId): ?WorkflowExecutionLock;
}
