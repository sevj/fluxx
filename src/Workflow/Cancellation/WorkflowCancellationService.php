<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Cancellation;

use Doctrine\ORM\EntityManagerInterface;
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

        // Same lock as the runtime's finalization, taken first: a cancel landing while a worker finalizes
        // the run waits for it instead of deadlocking on the execution lock row.
        return $this->entityManager->wrapInTransaction(function () use ($workflowRun, $trigger, $reason, $operatorUser): bool {
            $latestStepRuns = [];

            foreach ($this->workflowStepRunRepository->lockRunAndFindStepRunsOrdered($workflowRun) as $stepRun) {
                $latestStepRuns[$stepRun->stepName()] = $stepRun;
            }

            $this->entityManager->refresh($workflowRun);

            if (!$workflowRun->status()->isActive()) {
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
        });
    }
}
