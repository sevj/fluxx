<?php

declare(strict_types=1);

namespace Fluxx\Repository;

use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Fluxx\Entity\RuntimeWorkerState;

/**
 * @extends ServiceEntityRepository<RuntimeWorkerState>
 */
final class RuntimeWorkerStateRepository extends ServiceEntityRepository implements RuntimeWorkerStateLookupInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RuntimeWorkerState::class);
    }

    public function findOneByWorkerName(string $workerName): ?RuntimeWorkerState
    {
        /** @var RuntimeWorkerState|null $workerState */
        $workerState = $this->findOneBy(['workerName' => $workerName]);

        return $workerState;
    }

    /**
     * @return array<string, RuntimeWorkerState>
     */
    public function findIndexedByTransportName(string $transportName): array
    {
        /** @var list<RuntimeWorkerState> $workerStates */
        $workerStates = $this->createQueryBuilder('runtime_worker_state')
            ->andWhere('runtime_worker_state.transportName = :transportName')
            ->setParameter('transportName', $transportName)
            ->orderBy('runtime_worker_state.workerName', 'ASC')
            ->getQuery()
            ->getResult();

        $indexed = [];

        foreach ($workerStates as $workerState) {
            $indexed[$workerState->workerName()] = $workerState;
        }

        return $indexed;
    }

    public function hasActiveWorkerForRun(string $runId, DateTimeImmutable $heartbeatThreshold): bool
    {
        return (int) $this->createQueryBuilder('runtime_worker_state')
            ->select('COUNT(runtime_worker_state.id)')
            ->andWhere('runtime_worker_state.runId = :runId')
            ->andWhere('runtime_worker_state.status = :status')
            ->andWhere('runtime_worker_state.lastHeartbeatAt >= :heartbeatThreshold')
            ->setParameter('runId', $runId)
            ->setParameter('status', 'processing')
            ->setParameter('heartbeatThreshold', $heartbeatThreshold)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Returns the distinct run identifiers from the provided list that a worker
     * is still claiming: either a fresh processing heartbeat (between two
     * dispatched steps) or a processing slot started recently (mid-step, where
     * no heartbeat is emitted until the handler returns).
     *
     * @param list<string> $runIds
     * @return list<string>
     */
    public function findProcessingRunIds(
        array $runIds,
        DateTimeImmutable $heartbeatThreshold,
        DateTimeImmutable $processingStartedThreshold,
    ): array {
        if ($runIds === []) {
            return [];
        }

        /** @var list<RuntimeWorkerState> $workerStates */
        $workerStates = $this->createQueryBuilder('runtime_worker_state')
            ->andWhere('runtime_worker_state.runId IN (:runIds)')
            ->andWhere('runtime_worker_state.status = :status')
            ->setParameter('runIds', array_values(array_unique($runIds)))
            ->setParameter('status', 'processing')
            ->getQuery()
            ->getResult();

        $liveRunIds = [];

        foreach ($workerStates as $workerState) {
            $hasFreshHeartbeat = $workerState->lastHeartbeatAt() >= $heartbeatThreshold;
            $hasRecentProcessingStart = $workerState->startedProcessingAt() !== null
                && $workerState->startedProcessingAt() >= $processingStartedThreshold;

            if ($hasFreshHeartbeat || $hasRecentProcessingStart) {
                $liveRunIds[$workerState->runId()] = true;
            }
        }

        return array_keys($liveRunIds);
    }
}
