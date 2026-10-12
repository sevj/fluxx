<?php

declare(strict_types=1);

namespace Fluxx\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Fluxx\Entity\WorkflowPayloadContent;
use Fluxx\Entity\WorkflowRun;

/**
 * @extends ServiceEntityRepository<WorkflowPayloadContent>
 */
final class WorkflowPayloadContentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowPayloadContent::class);
    }

    public function findOneByWorkflowRunAndContentHash(WorkflowRun $workflowRun, string $contentHash): ?WorkflowPayloadContent
    {
        return $this->findOneBy([
            'workflowRun' => $workflowRun,
            'contentHash' => $contentHash,
        ]);
    }
}
