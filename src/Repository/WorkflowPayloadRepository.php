<?php

declare(strict_types=1);

namespace Fluxx\Repository;

use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Fluxx\Entity\WorkflowPayload;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;

/**
 * @extends ServiceEntityRepository<WorkflowPayload>
 */
final class WorkflowPayloadRepository extends ServiceEntityRepository implements WorkflowPayloadLookupInterface
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

    public function countBeforeDate(DateTimeImmutable $before, ?string $workflowCode = null): int
    {
        $qb = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from('fluxx_workflow_payload', 'p')
            ->where('p.created_at < :before')
            ->setParameter('before', $before, Types::DATETIME_IMMUTABLE);

        if ($workflowCode !== null) {
            $qb->innerJoin('p', 'fluxx_workflow_run', 'r', 'r.id = p.workflow_run_id')
                ->andWhere('r.workflow_name = :workflowCode')
                ->setParameter('workflowCode', $workflowCode);
        }

        return (int) $qb->fetchOne();
    }

    public function deleteBeforeDate(DateTimeImmutable $before, ?string $workflowCode = null): int
    {
        if ($workflowCode === null) {
            return (int) $this->getEntityManager()->getConnection()->createQueryBuilder()
                ->delete('fluxx_workflow_payload')
                ->where('created_at < :before')
                ->setParameter('before', $before, Types::DATETIME_IMMUTABLE)
                ->executeStatement();
        }

        $subQb = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('r.id')
            ->from('fluxx_workflow_run', 'r')
            ->where('r.workflow_name = :workflowCode')
            ->setParameter('workflowCode', $workflowCode);

        $sql = 'DELETE FROM fluxx_workflow_payload WHERE created_at < :before AND workflow_run_id IN (' . $subQb->getSQL() . ')';

        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            $sql,
            ['before' => $before, 'workflowCode' => $workflowCode],
            ['before' => Types::DATETIME_IMMUTABLE],
        );
    }

    public function deletePayloadsOfPrunedRunsBeforeDate(DateTimeImmutable $before, ?string $workflowCode = null): int
    {
        $subQb = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('r.id')
            ->from('fluxx_workflow_run', 'r')
            ->where('r.status = :prunedStatus')
            ->andWhere('r.created_at < :before')
            ->setParameter('prunedStatus', \Fluxx\Entity\Enum\WorkflowRunStatus::PayloadsPruned->value)
            ->setParameter('before', $before, Types::DATETIME_IMMUTABLE);

        if ($workflowCode !== null) {
            $subQb->andWhere('r.workflow_name = :workflowCode')
                ->setParameter('workflowCode', $workflowCode);
        }

        $params = ['prunedStatus' => \Fluxx\Entity\Enum\WorkflowRunStatus::PayloadsPruned->value, 'before' => $before];
        $types = ['before' => Types::DATETIME_IMMUTABLE];

        if ($workflowCode !== null) {
            $params['workflowCode'] = $workflowCode;
        }

        $sql = 'DELETE FROM fluxx_workflow_payload WHERE workflow_run_id IN (' . $subQb->getSQL() . ')';

        return (int) $this->getEntityManager()->getConnection()->executeStatement($sql, $params, $types);
    }

    public function countPayloadsOfPrunedRunsBeforeDate(DateTimeImmutable $before, ?string $workflowCode = null): int
    {
        $qb = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from('fluxx_workflow_payload', 'p')
            ->innerJoin('p', 'fluxx_workflow_run', 'r', 'r.id = p.workflow_run_id')
            ->where('r.status = :prunedStatus')
            ->andWhere('r.created_at < :before')
            ->setParameter('prunedStatus', \Fluxx\Entity\Enum\WorkflowRunStatus::PayloadsPruned->value)
            ->setParameter('before', $before, Types::DATETIME_IMMUTABLE);

        if ($workflowCode !== null) {
            $qb->andWhere('r.workflow_name = :workflowCode')
                ->setParameter('workflowCode', $workflowCode);
        }

        return (int) $qb->fetchOne();
    }
}
