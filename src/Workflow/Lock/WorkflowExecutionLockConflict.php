<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Lock;

use RuntimeException;

final class WorkflowExecutionLockConflict extends RuntimeException
{
    public function __construct(
        private readonly string $workflowCode,
        private readonly string $lockKey,
        private readonly string $activeRunId,
    ) {
        parent::__construct(sprintf(
            'Workflow "%s" is already locked by run "%s" for key "%s".',
            $workflowCode,
            $activeRunId,
            $lockKey,
        ));
    }

    public function workflowCode(): string
    {
        return $this->workflowCode;
    }

    public function lockKey(): string
    {
        return $this->lockKey;
    }

    public function activeRunId(): string
    {
        return $this->activeRunId;
    }
}
