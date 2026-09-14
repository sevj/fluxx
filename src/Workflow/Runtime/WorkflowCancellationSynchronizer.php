<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Runtime;

use Fluxx\Entity\Enum\WorkflowRunStatus;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Repository\WorkflowRunLookupInterface;

final readonly class WorkflowCancellationSynchronizer
{
    public function __construct(
        private WorkflowRunLookupInterface $workflowRunRepository,
    ) {
    }

    public function synchronizeIfNeeded(WorkflowRun $workflowRun): bool
    {
        $persistedState = $this->workflowRunRepository->findPersistedRunStateByRunId($workflowRun->runId());

        if (($persistedState['status'] ?? null) !== WorkflowRunStatus::Cancelled->value) {
            return false;
        }

        $workflowRun->replaceMetadata($persistedState['metadata']);
        $workflowRun->markCancelled($persistedState['finishedAt']);

        return true;
    }
}
