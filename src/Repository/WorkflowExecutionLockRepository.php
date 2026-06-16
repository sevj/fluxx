<?php

declare(strict_types=1);

namespace Fluxx\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Fluxx\Entity\WorkflowExecutionLock;

/**
 * @extends ServiceEntityRepository<WorkflowExecutionLock>
 */
final class WorkflowExecutionLockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowExecutionLock::class);
    }

    public function findActiveByLockKey(string $lockKey): ?WorkflowExecutionLock
    {
        /** @var WorkflowExecutionLock|null $lock */
        $lock = $this->createQueryBuilder('workflow_execution_lock')
            ->andWhere('workflow_execution_lock.lockKey = :lockKey')
            ->andWhere('workflow_execution_lock.releasedAt IS NULL')
            ->setParameter('lockKey', $lockKey)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $lock;
    }

    public function findActiveByOwnerRunId(string $ownerRunId): ?WorkflowExecutionLock
    {
        /** @var WorkflowExecutionLock|null $lock */
        $lock = $this->createQueryBuilder('workflow_execution_lock')
            ->andWhere('workflow_execution_lock.ownerRunId = :ownerRunId')
            ->andWhere('workflow_execution_lock.releasedAt IS NULL')
            ->setParameter('ownerRunId', $ownerRunId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $lock;
    }

    /**
     * @return list<WorkflowExecutionLock>
     */
    public function findActiveOrdered(): array
    {
        return $this->createQueryBuilder('workflow_execution_lock')
            ->andWhere('workflow_execution_lock.releasedAt IS NULL')
            ->orderBy('workflow_execution_lock.acquiredAt', 'DESC')
            ->addOrderBy('workflow_execution_lock.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
