<?php

declare(strict_types=1);

namespace Fluxx\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Fluxx\Entity\WorkflowPayload;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;

/**
 * @extends ServiceEntityRepository<WorkflowPayload>
 */
final class WorkflowPayloadRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowPayload::class);
    }

    /**
     * @return list<WorkflowPayload>
     */
    public function findBySourceStepRunOrdered(WorkflowStepRun $stepRun): array
    {
        return $this->createQueryBuilder('workflow_payload')
            ->andWhere('workflow_payload.sourceStepRun = :stepRun')
            ->setParameter('stepRun', $stepRun)
            ->orderBy('workflow_payload.sequence', 'ASC')
            ->addOrderBy('workflow_payload.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findLatestByWorkflowRunAndTargetStepType(
        WorkflowRun $workflowRun,
        string $targetStepType,
    ): ?WorkflowPayload {
        /** @var WorkflowPayload|null $payload */
        $payload = $this->createQueryBuilder('workflow_payload')
            ->andWhere('workflow_payload.workflowRun = :workflowRun')
            ->andWhere('workflow_payload.targetStepType = :targetStepType')
            ->setParameter('workflowRun', $workflowRun)
            ->setParameter('targetStepType', $targetStepType)
            ->orderBy('workflow_payload.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $payload;
    }

    /**
     * @return list<WorkflowPayload>
     */
    public function findByWorkflowRunAndTargetStepTypeOrdered(
        WorkflowRun $workflowRun,
        string $targetStepType,
    ): array {
        return $this->createQueryBuilder('workflow_payload')
            ->andWhere('workflow_payload.workflowRun = :workflowRun')
            ->andWhere('workflow_payload.targetStepType = :targetStepType')
            ->setParameter('workflowRun', $workflowRun)
            ->setParameter('targetStepType', $targetStepType)
            ->orderBy('workflow_payload.sequence', 'ASC')
            ->addOrderBy('workflow_payload.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<WorkflowPayload>
     */
    public function findByWorkflowRunAndTargetStepNameOrdered(
        WorkflowRun $workflowRun,
        string $targetStepName,
    ): array {
        return $this->createQueryBuilder('workflow_payload')
            ->andWhere('workflow_payload.workflowRun = :workflowRun')
            ->andWhere('workflow_payload.targetStepName = :targetStepName')
            ->setParameter('workflowRun', $workflowRun)
            ->setParameter('targetStepName', $targetStepName)
            ->orderBy('workflow_payload.sequence', 'ASC')
            ->addOrderBy('workflow_payload.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
