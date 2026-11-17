<?php

declare(strict_types=1);

namespace Fluxx\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowRunReport;

/**
 * @extends ServiceEntityRepository<WorkflowRunReport>
 */
final class WorkflowRunReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowRunReport::class);
    }

    public function findOneByRun(WorkflowRun $workflowRun): ?WorkflowRunReport
    {
        return $this->findOneBy(['workflowRun' => $workflowRun]);
    }

    /**
     * Stores the report of a run, replacing the one it already has.
     *
     * @param array<string, mixed> $content
     */
    public function save(WorkflowRun $workflowRun, int $formatVersion, array $content): WorkflowRunReport
    {
        $report = $this->findOneByRun($workflowRun);

        if ($report === null) {
            $report = new WorkflowRunReport($workflowRun, $formatVersion, $content);
            $this->getEntityManager()->persist($report);
        } else {
            $report->replace($formatVersion, $content);
        }

        $this->getEntityManager()->flush();

        return $report;
    }
}
