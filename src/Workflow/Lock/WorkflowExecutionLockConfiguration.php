<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Lock;

use Fluxx\Entity\Enum\WorkflowExecutionLockScope;
use InvalidArgumentException;

final readonly class WorkflowExecutionLockConfiguration
{
    public function __construct(
        private WorkflowExecutionLockScope $scope = WorkflowExecutionLockScope::Workflow,
        private ?string $businessPartitionMetadataKey = null,
        private int $staleTimeoutSeconds = 900,
    ) {
        if ($this->scope === WorkflowExecutionLockScope::WorkflowBusinessPartition && $this->businessPartitionMetadataKey === null) {
            throw new InvalidArgumentException('A business partition lock scope requires a metadata key.');
        }

        if ($this->staleTimeoutSeconds < 1) {
            throw new InvalidArgumentException('The stale lock timeout must be greater than zero.');
        }
    }

    public function scope(): WorkflowExecutionLockScope
    {
        return $this->scope;
    }

    public function businessPartitionMetadataKey(): ?string
    {
        return $this->businessPartitionMetadataKey;
    }

    public function staleTimeoutSeconds(): int
    {
        return $this->staleTimeoutSeconds;
    }
}
