<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Cancellation;

use Doctrine\ORM\EntityManagerInterface;
use Fluxx\Entity\Enum\WorkflowRunStatus;
use Fluxx\Entity\Enum\WorkflowStepRunStatus;
use Fluxx\Repository\WorkflowRunRepository;
use Fluxx\Repository\WorkflowStepRunRepository;
use Fluxx\Workflow\Lock\WorkflowExecutionLockManager;
use RuntimeException;

final readonly class WorkflowCancellationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private WorkflowRunRepository $workflowRunRepository,
        private WorkflowStepRunRepository $workflowStepRunRepository,
        private WorkflowExecutionLockManager $workflowExecutionLockManager,
    ) {
    }

    public function cancel(
        string $runId,
        string $trigger = 'ui',
        ?string $reason = null,
        ?string $operatorUser = null,
    ): bool {
        $workflowRun = $this->workflowRunRepository->findOneByRunId($runId);

        if ($workflowRun === null) {
            throw new RuntimeException(sprintf('Workflow run "%s" was not found.', $runId));
        }

        if (in_array($workflowRun->status(), [
            WorkflowRunStatus::Completed,
            WorkflowRunStatus::Failed,
            WorkflowRunStatus::PartiallyFailed,
            WorkflowRunStatus::Cancelled,
        ], true)) {
            return false;
        }

        $metadata = $workflowRun->metadata();
        unset($metadata['error']);
        $metadata['cancellation'] = [
            'trigger' => $trigger,
            'reason' => $reason,
            'operator_user' => $operatorUser,
            'cancelled_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];
        $workflowRun->replaceMetadata($metadata);
        $workflowRun->markCancelled();

        $latestStepRuns = [];

        foreach ($this->workflowStepRunRepository->findByWorkflowRunOrdered($workflowRun) as $stepRun) {
            $latestStepRuns[$stepRun->stepName()] = $stepRun;
        }

        foreach ($latestStepRuns as $stepRun) {
            if (in_array($stepRun->status(), [
                WorkflowStepRunStatus::Pending,
                WorkflowStepRunStatus::Relaunched,
                WorkflowStepRunStatus::Retrying,
            ], true)) {
                $stepRun->markCancelled();
            }
        }

        $this->workflowExecutionLockManager->releaseForRun($workflowRun, 'cancelled');
        $this->entityManager->flush();

        return true;
    }
}
