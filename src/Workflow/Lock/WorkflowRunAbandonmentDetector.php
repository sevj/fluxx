<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Lock;

use DateTimeImmutable;
use Fluxx\Entity\Enum\WorkflowStepRunStatus;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Repository\RuntimeWorkerStateLookupInterface;
use Fluxx\Repository\WorkflowStepRunLookupInterface;

/**
 * Tells whether an unfinished run can still make progress.
 *
 * No worker on a run does not mean it is dead: workers go idle after each message,
 * so a healthy run has none while its next step waits in the queue. A run is only
 * abandoned when, on top of that, nothing has happened since the given threshold.
 */
final readonly class WorkflowRunAbandonmentDetector
{
    public function __construct(
        private WorkflowStepRunLookupInterface $workflowStepRunRepository,
        private RuntimeWorkerStateLookupInterface $runtimeWorkerStateRepository,
    ) {
    }

    public function isAbandoned(WorkflowRun $workflowRun, DateTimeImmutable $inactiveSince): bool
    {
        $stepRuns = $this->workflowStepRunRepository->findByWorkflowRunOrdered($workflowRun);
        $now = new DateTimeImmutable();

        foreach ($stepRuns as $stepRun) {
            if ($stepRun->status() === WorkflowStepRunStatus::Retrying && $stepRun->nextRetryAt() !== null && $stepRun->nextRetryAt() > $now) {
                return false;
            }
        }

        if ($workflowRun->lastActivityAt($stepRuns) >= $inactiveSince) {
            return false;
        }

        return !$this->runtimeWorkerStateRepository->hasActiveWorkerForRun($workflowRun->runId(), $inactiveSince);
    }
}
