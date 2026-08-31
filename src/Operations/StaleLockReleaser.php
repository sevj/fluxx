<?php

declare(strict_types=1);

namespace Fluxx\Operations;

use Doctrine\ORM\EntityManagerInterface;
use Fluxx\Entity\Enum\WorkflowRunStatus;
use Fluxx\Entity\WorkflowExecutionLock;
use Fluxx\Repository\RuntimeWorkerStateLookupInterface;
use Fluxx\Repository\WorkflowExecutionLockStoreInterface;
use Fluxx\Repository\WorkflowRunLookupInterface;

/**
 * Releases workflow execution locks that no longer protect a live execution.
 *
 * A lock is considered releasable when its owner run is missing or already in a
 * terminal state, or when no worker has pushed a heartbeat for that run within
 * the stale threshold (the same signal used by the lock manager's stale
 * recovery). This mirrors {@see \Fluxx\Workflow\Lock\WorkflowExecutionLockManager::shouldRecoverStaleLock()}
 * but applies it as an explicit, operator-triggered cleanup that also covers
 * locks whose owning run has no lock configuration to recover from.
 */
final readonly class StaleLockReleaser
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private WorkflowExecutionLockStoreInterface $workflowExecutionLockRepository,
        private WorkflowRunLookupInterface $workflowRunRepository,
        private RuntimeWorkerStateLookupInterface $runtimeWorkerStateRepository,
    ) {
    }

    /**
     * Releases the active lock owned by a given run, regardless of staleness.
     *
     * Use this for an operator-triggered, targeted release of a single blocked lock.
     */
    public function releaseForRun(string $runId, string $reason = 'manual_release'): bool
    {
        $lock = $this->workflowExecutionLockRepository->findActiveByOwnerRunId($runId);

        if ($lock === null) {
            return false;
        }

        $lock->release($reason);
        $this->entityManager->flush();

        return true;
    }

    /**
     * Releases every active lock whose owner run is gone, terminal, or has no
     * live worker heartbeat within the stale threshold.
     *
     * @return list<array{runId: string, lockKey: string, reason: string}>
     */
    public function releaseStale(int $staleHeartbeatSeconds = 120): array
    {
        $heartbeatThreshold = new \DateTimeImmutable(sprintf('-%d seconds', $staleHeartbeatSeconds));
        $released = [];

        foreach ($this->workflowExecutionLockRepository->findActiveOrdered() as $lock) {
            $reason = $this->resolveReleasableReason($lock, $heartbeatThreshold);

            if ($reason === null) {
                continue;
            }

            $lock->release($reason);
            $released[] = [
                'runId' => $lock->ownerRunId(),
                'lockKey' => $lock->lockKey(),
                'reason' => $reason,
            ];
        }

        if ($released !== []) {
            $this->entityManager->flush();
        }

        return $released;
    }

    private function resolveReleasableReason(WorkflowExecutionLock $lock, \DateTimeImmutable $heartbeatThreshold): ?string
    {
        $ownerRun = $this->workflowRunRepository->findOneByRunId($lock->ownerRunId());

        if ($ownerRun === null) {
            return 'owner_run_missing';
        }

        if (in_array($ownerRun->status(), [
            WorkflowRunStatus::Completed,
            WorkflowRunStatus::Cancelled,
            WorkflowRunStatus::Failed,
            WorkflowRunStatus::PartiallyFailed,
        ], true)) {
            return 'owner_run_terminal';
        }

        if (!$this->runtimeWorkerStateRepository->hasActiveWorkerForRun($ownerRun->runId(), $heartbeatThreshold)) {
            return 'worker_heartbeat_stale';
        }

        return null;
    }
}
