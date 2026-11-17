<?php

declare(strict_types=1);

namespace Fluxx\Repository;

use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;

interface WorkflowStepRunLookupInterface
{
    /**
     * @return list<WorkflowStepRun>
     */
    public function findByWorkflowRunOrdered(WorkflowRun $workflowRun): array;

    /**
     * Locks the run row until the current transaction ends (PostgreSQL only), then returns the step
     * runs as stored, refreshing the ones already loaded: workers finalizing the same run take turns
     * and each one decides from what the others committed.
     *
     * @return list<WorkflowStepRun>
     */
    public function lockRunAndFindStepRunsOrdered(WorkflowRun $workflowRun): array;

    public function findLatestByWorkflowRunAndStepName(WorkflowRun $workflowRun, string $stepName): ?WorkflowStepRun;

    /**
     * @param list<string> $stepNames
     * @return array<string, WorkflowStepRun>
     */
    public function findCompletedByWorkflowRunAndStepNames(WorkflowRun $workflowRun, array $stepNames): array;

    public function findLatestCompletedByWorkflowNameAndStepNameAndIdempotenceKey(
        string $workflowName,
        string $stepName,
        string $idempotenceKey,
    ): ?WorkflowStepRun;
}
