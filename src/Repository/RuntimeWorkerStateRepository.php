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
final class RuntimeWorkerStateRepository extends ServiceEntityRepository
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
}
