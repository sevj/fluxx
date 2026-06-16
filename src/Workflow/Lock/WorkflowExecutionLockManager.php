<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Lock;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Fluxx\Entity\Enum\WorkflowExecutionLockScope;
use Fluxx\Entity\Enum\WorkflowRunStatus;
use Fluxx\Entity\WorkflowExecutionLock;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Repository\RuntimeWorkerStateRepository;
use Fluxx\Repository\WorkflowExecutionLockRepository;
use Fluxx\Repository\WorkflowRunRepository;
use Fluxx\Workflow\WorkflowDefinition;
use InvalidArgumentException;

final readonly class WorkflowExecutionLockManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private WorkflowExecutionLockRepository $workflowExecutionLockRepository,
        private WorkflowRunRepository $workflowRunRepository,
        private RuntimeWorkerStateRepository $runtimeWorkerStateRepository,
    ) {
    }

    public function acquire(WorkflowRun $workflowRun, WorkflowDefinition $definition): ?WorkflowExecutionLock
    {
        $configuration = $definition->lock();

        if ($configuration === null) {
            return null;
        }

        $lockKey = $this->buildLockKey($workflowRun, $configuration);
        $activeLock = $this->workflowExecutionLockRepository->findActiveByLockKey($lockKey);

        if ($activeLock !== null && $activeLock->ownerRunId() !== $workflowRun->runId()) {
            if ($this->shouldRecoverStaleLock($activeLock, $configuration)) {
                $activeLock->release('stale_recovered');
            } else {
                throw new WorkflowExecutionLockConflict(
                    workflowCode: $definition->code(),
                    lockKey: $lockKey,
                    activeRunId: $activeLock->ownerRunId(),
                );
            }
        }

        $lock = new WorkflowExecutionLock(
            workflowName: $definition->code(),
            ownerRunId: $workflowRun->runId(),
            lockKey: $lockKey,
            scope: $configuration->scope(),
            businessPartitionKey: $configuration->businessPartitionMetadataKey() !== null
                ? (string) ($workflowRun->metadata()[$configuration->businessPartitionMetadataKey()] ?? '')
                : null,
        );

        $workflowRun->attachExecutionLock($lock->lockKey(), $lock->scope());

        $this->entityManager->persist($lock);

        return $lock;
    }

    public function releaseForRun(WorkflowRun $workflowRun, string $reason): void
    {
        $lock = $this->workflowExecutionLockRepository->findActiveByOwnerRunId($workflowRun->runId());

        if ($lock === null) {
            return;
        }

        $lock->release($reason);
    }

    private function shouldRecoverStaleLock(
        WorkflowExecutionLock $lock,
        WorkflowExecutionLockConfiguration $configuration,
    ): bool {
        $ownerRun = $this->workflowRunRepository->findOneByRunId($lock->ownerRunId());

        if ($ownerRun === null) {
            return true;
        }

        if (in_array($ownerRun->status(), [
            WorkflowRunStatus::Completed,
            WorkflowRunStatus::Failed,
            WorkflowRunStatus::PartiallyFailed,
        ], true)) {
            return true;
        }

        $heartbeatThreshold = new DateTimeImmutable(sprintf('-%d seconds', $configuration->staleTimeoutSeconds()));

        return !$this->runtimeWorkerStateRepository->hasActiveWorkerForRun($ownerRun->runId(), $heartbeatThreshold);
    }

    private function buildLockKey(
        WorkflowRun $workflowRun,
        WorkflowExecutionLockConfiguration $configuration,
    ): string {
        $segments = [$workflowRun->workflowName()];

        switch ($configuration->scope()) {
            case WorkflowExecutionLockScope::Workflow:
                break;

            case WorkflowExecutionLockScope::WorkflowSource:
                $segments[] = $workflowRun->sourceSystem();
                break;

            case WorkflowExecutionLockScope::WorkflowSourceTarget:
                $segments[] = $workflowRun->sourceSystem();
                $segments[] = $workflowRun->targetSystem();
                break;

            case WorkflowExecutionLockScope::WorkflowBusinessPartition:
                $metadataKey = $configuration->businessPartitionMetadataKey();
                $partitionValue = $metadataKey !== null ? $workflowRun->metadata()[$metadataKey] ?? null : null;

                if (!is_scalar($partitionValue) || (string) $partitionValue === '') {
                    throw new InvalidArgumentException(sprintf(
                        'Workflow "%s" requires metadata key "%s" to build its execution lock.',
                        $workflowRun->workflowName(),
                        (string) $metadataKey,
                    ));
                }

                $segments[] = (string) $partitionValue;
                break;
        }

        return implode(':', $segments);
    }
}
