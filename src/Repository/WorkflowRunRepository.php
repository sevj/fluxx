<?php

declare(strict_types=1);

namespace Fluxx\Repository;

use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Fluxx\Entity\Enum\WorkflowRunStatus;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Ui\WorkflowRunFilters;

/**
 * @extends ServiceEntityRepository<WorkflowRun>
 */
final class WorkflowRunRepository extends ServiceEntityRepository implements WorkflowRunLookupInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowRun::class);
    }

    public function findOneByRunId(string $runId): ?WorkflowRun
    {
        return $this->findOneBy(['runId' => $runId]);
    }

    /**
     * @param list<string> $runIds
     * @return array<string, WorkflowRun>
     */
    public function findByRunIdsIndexed(array $runIds): array
    {
        if ($runIds === []) {
            return [];
        }

        /** @var list<WorkflowRun> $runs */
        $runs = $this->createQueryBuilder('workflow_run')
            ->andWhere('workflow_run.runId IN (:runIds)')
            ->setParameter('runIds', array_values(array_unique($runIds)))
            ->getQuery()
            ->getResult();

        $indexed = [];

        foreach ($runs as $run) {
            $indexed[$run->runId()] = $run;
        }

        return $indexed;
    }

    /**
     * @return list<WorkflowRun>
     */
    public function findLatestByWorkflowName(string $workflowName, int $limit = 20): array
    {
        return $this->createQueryBuilder('workflow_run')
            ->andWhere('workflow_run.workflowName = :workflowName')
            ->setParameter('workflowName', $workflowName)
            ->orderBy('workflow_run.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countByWorkflowName(string $workflowName): int
    {
        return (int) $this->createQueryBuilder('workflow_run')
            ->select('COUNT(workflow_run.id)')
            ->andWhere('workflow_run.workflowName = :workflowName')
            ->setParameter('workflowName', $workflowName)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findLatestOneByWorkflowName(string $workflowName): ?WorkflowRun
    {
        /** @var WorkflowRun|null $workflowRun */
        $workflowRun = $this->createQueryBuilder('workflow_run')
            ->andWhere('workflow_run.workflowName = :workflowName')
            ->setParameter('workflowName', $workflowName)
            ->orderBy('workflow_run.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $workflowRun;
    }

    /**
     * @return list<WorkflowRun>
     */
    public function findPaginatedByWorkflowName(string $workflowName, int $limit, int $offset): array
    {
        return $this->createQueryBuilder('workflow_run')
            ->andWhere('workflow_run.workflowName = :workflowName')
            ->setParameter('workflowName', $workflowName)
            ->orderBy('workflow_run.createdAt', 'DESC')
            ->addOrderBy('workflow_run.id', 'DESC')
            ->setFirstResult(max($offset, 0))
            ->setMaxResults(max($limit, 1))
            ->getQuery()
            ->getResult();
    }

    public function countErroredByWorkflowName(string $workflowName): int
    {
        return (int) $this->createQueryBuilder('workflow_run')
            ->select('COUNT(workflow_run.id)')
            ->andWhere('workflow_run.workflowName = :workflowName')
            ->andWhere('workflow_run.status IN (:statuses)')
            ->setParameter('workflowName', $workflowName)
            ->setParameter('statuses', [
                WorkflowRunStatus::Failed,
                WorkflowRunStatus::PartiallyFailed,
            ])
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findLatestErrorAtByWorkflowName(string $workflowName): ?DateTimeImmutable
    {
        /** @var WorkflowRun|null $workflowRun */
        $workflowRun = $this->createQueryBuilder('workflow_run')
            ->andWhere('workflow_run.workflowName = :workflowName')
            ->andWhere('workflow_run.status IN (:statuses)')
            ->setParameter('workflowName', $workflowName)
            ->setParameter('statuses', [
                WorkflowRunStatus::Failed,
                WorkflowRunStatus::PartiallyFailed,
            ])
            ->orderBy('workflow_run.finishedAt', 'DESC')
            ->addOrderBy('workflow_run.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $workflowRun?->finishedAt() ?? $workflowRun?->createdAt();
    }

    /**
     * @return list<WorkflowRun>
     */
    public function findCreatedSinceByWorkflowName(string $workflowName, DateTimeImmutable $startAt): array
    {
        return $this->createQueryBuilder('workflow_run')
            ->andWhere('workflow_run.workflowName = :workflowName')
            ->andWhere('workflow_run.createdAt >= :startAt')
            ->setParameter('workflowName', $workflowName)
            ->setParameter('startAt', $startAt)
            ->orderBy('workflow_run.createdAt', 'ASC')
            ->addOrderBy('workflow_run.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countByFilters(WorkflowRunFilters $filters): int
    {
        return (int) $this->createFilteredQueryBuilder($filters)
            ->select('COUNT(workflow_run.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<WorkflowRun>
     */
    public function findPaginatedByFilters(WorkflowRunFilters $filters, int $limit, int $offset): array
    {
        return $this->createFilteredQueryBuilder($filters)
            ->orderBy('workflow_run.createdAt', 'DESC')
            ->addOrderBy('workflow_run.id', 'DESC')
            ->setFirstResult(max($offset, 0))
            ->setMaxResults(max($limit, 1))
            ->getQuery()
            ->getResult();
    }

    private function createFilteredQueryBuilder(WorkflowRunFilters $filters): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('workflow_run');
        $errorStatuses = [
            WorkflowRunStatus::Failed,
            WorkflowRunStatus::PartiallyFailed,
        ];

        if ($filters->searchQuery() !== '') {
            $queryBuilder
                ->andWhere('
                    LOWER(workflow_run.runId) LIKE :search
                    OR LOWER(workflow_run.workflowName) LIKE :search
                    OR LOWER(workflow_run.sourceSystem) LIKE :search
                    OR LOWER(workflow_run.targetSystem) LIKE :search
                    OR LOWER(workflow_run.trigger) LIKE :search
                    OR LOWER(COALESCE(workflow_run.batchId, \'\')) LIKE :search
                    OR LOWER(COALESCE(workflow_run.errorMessage, \'\')) LIKE :search
                    OR LOWER(COALESCE(workflow_run.lockKey, \'\')) LIKE :search
                ')
                ->setParameter('search', '%' . mb_strtolower($filters->searchQuery()) . '%');
        }

        if ($filters->workflowCode() !== null) {
            $queryBuilder
                ->andWhere('workflow_run.workflowName = :workflowName')
                ->setParameter('workflowName', $filters->workflowCode());
        }

        if ($filters->status() !== null) {
            $queryBuilder
                ->andWhere('workflow_run.status = :status')
                ->setParameter('status', $filters->status());
        }

        if ($filters->sourceSystem() !== null) {
            $queryBuilder
                ->andWhere('workflow_run.sourceSystem = :sourceSystem')
                ->setParameter('sourceSystem', $filters->sourceSystem());
        }

        if ($filters->targetSystem() !== null) {
            $queryBuilder
                ->andWhere('workflow_run.targetSystem = :targetSystem')
                ->setParameter('targetSystem', $filters->targetSystem());
        }

        if ($filters->errorPresence() === 'with') {
            $queryBuilder
                ->andWhere('workflow_run.status IN (:errorStatuses)')
                ->setParameter('errorStatuses', $errorStatuses);
        } elseif ($filters->errorPresence() === 'without') {
            $queryBuilder
                ->andWhere('workflow_run.status NOT IN (:errorStatuses)')
                ->setParameter('errorStatuses', $errorStatuses);
        }

        if ($filters->dateFrom() !== null) {
            $queryBuilder
                ->andWhere('workflow_run.createdAt >= :dateFrom')
                ->setParameter('dateFrom', $filters->dateFrom());
        }

        if ($filters->dateTo() !== null) {
            $queryBuilder
                ->andWhere('workflow_run.createdAt <= :dateTo')
                ->setParameter('dateTo', $filters->dateTo());
        }

        return $queryBuilder;
    }
}
