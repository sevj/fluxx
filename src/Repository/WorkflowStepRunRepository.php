<?php

declare(strict_types=1);

namespace Fluxx\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Fluxx\Entity\Enum\WorkflowStepRunStatus;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;

/**
 * @extends ServiceEntityRepository<WorkflowStepRun>
 */
final class WorkflowStepRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowStepRun::class);
    }

    /**
     * @return list<WorkflowStepRun>
     */
    public function findByWorkflowRunOrdered(WorkflowRun $workflowRun): array
    {
        return $this->createQueryBuilder('workflow_step_run')
            ->andWhere('workflow_step_run.workflowRun = :workflowRun')
            ->setParameter('workflowRun', $workflowRun)
            ->orderBy('workflow_step_run.position', 'ASC')
            ->addOrderBy('workflow_step_run.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findLatestByWorkflowRunAndType(
        WorkflowRun $workflowRun,
        string $stepType,
    ): ?WorkflowStepRun {
        /** @var WorkflowStepRun|null $stepRun */
        $stepRun = $this->createQueryBuilder('workflow_step_run')
            ->andWhere('workflow_step_run.workflowRun = :workflowRun')
            ->andWhere('workflow_step_run.stepType = :stepType')
            ->setParameter('workflowRun', $workflowRun)
            ->setParameter('stepType', $stepType)
            ->orderBy('workflow_step_run.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $stepRun;
    }

    public function findLatestByWorkflowRunAndStepName(WorkflowRun $workflowRun, string $stepName): ?WorkflowStepRun
    {
        /** @var WorkflowStepRun|null $stepRun */
        $stepRun = $this->createQueryBuilder('workflow_step_run')
            ->andWhere('workflow_step_run.workflowRun = :workflowRun')
            ->andWhere('workflow_step_run.stepName = :stepName')
            ->setParameter('workflowRun', $workflowRun)
            ->setParameter('stepName', $stepName)
            ->orderBy('workflow_step_run.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $stepRun;
    }

    /**
     * @param list<string> $stepNames
     * @return array<string, WorkflowStepRun>
     */
    public function findCompletedByWorkflowRunAndStepNames(WorkflowRun $workflowRun, array $stepNames): array
    {
        if ($stepNames === []) {
            return [];
        }

        /** @var list<WorkflowStepRun> $stepRuns */
        $stepRuns = $this->createQueryBuilder('workflow_step_run')
            ->andWhere('workflow_step_run.workflowRun = :workflowRun')
            ->andWhere('workflow_step_run.stepName IN (:stepNames)')
            ->andWhere('workflow_step_run.status = :status')
            ->setParameter('workflowRun', $workflowRun)
            ->setParameter('stepNames', $stepNames)
            ->setParameter('status', WorkflowStepRunStatus::Completed)
            ->orderBy('workflow_step_run.id', 'DESC')
            ->getQuery()
            ->getResult();

        $grouped = [];

        foreach ($stepRuns as $stepRun) {
            $grouped[$stepRun->stepName()] ??= $stepRun;
        }

        return $grouped;
    }

    /**
     * @param list<WorkflowRun> $workflowRuns
     * @return array<string, list<WorkflowStepRun>>
     */
    public function findByWorkflowRunsGrouped(array $workflowRuns): array
    {
        if ($workflowRuns === []) {
            return [];
        }

        /** @var list<WorkflowStepRun> $stepRuns */
        $stepRuns = $this->createQueryBuilder('workflow_step_run')
            ->andWhere('workflow_step_run.workflowRun IN (:workflowRuns)')
            ->setParameter('workflowRuns', $workflowRuns)
            ->orderBy('workflow_step_run.position', 'ASC')
            ->addOrderBy('workflow_step_run.id', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];

        foreach ($stepRuns as $stepRun) {
            $grouped[$stepRun->workflowRun()->runId()][] = $stepRun;
        }

        return $grouped;
    }

    public function findOneByWorkflowNameRunIdAndStepName(string $workflowName, string $runId, string $stepName): ?WorkflowStepRun
    {
        /** @var WorkflowStepRun|null $stepRun */
        $stepRun = $this->createQueryBuilder('workflow_step_run')
            ->innerJoin('workflow_step_run.workflowRun', 'workflow_run')
            ->addSelect('workflow_run')
            ->andWhere('workflow_run.workflowName = :workflowName')
            ->andWhere('workflow_run.runId = :runId')
            ->andWhere('workflow_step_run.stepName = :stepName')
            ->setParameter('workflowName', $workflowName)
            ->setParameter('runId', $runId)
            ->setParameter('stepName', $stepName)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $stepRun;
    }

    public function findLatestCompletedByWorkflowNameAndStepNameAndIdempotenceKey(
        string $workflowName,
        string $stepName,
        string $idempotenceKey,
    ): ?WorkflowStepRun {
        /** @var WorkflowStepRun|null $stepRun */
        $stepRun = $this->createQueryBuilder('workflow_step_run')
            ->innerJoin('workflow_step_run.workflowRun', 'workflow_run')
            ->addSelect('workflow_run')
            ->andWhere('workflow_run.workflowName = :workflowName')
            ->andWhere('workflow_step_run.stepName = :stepName')
            ->andWhere('workflow_step_run.idempotenceKey = :idempotenceKey')
            ->andWhere('workflow_step_run.status = :status')
            ->setParameter('workflowName', $workflowName)
            ->setParameter('stepName', $stepName)
            ->setParameter('idempotenceKey', $idempotenceKey)
            ->setParameter('status', WorkflowStepRunStatus::Completed)
            ->orderBy('workflow_step_run.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $stepRun;
    }
}
