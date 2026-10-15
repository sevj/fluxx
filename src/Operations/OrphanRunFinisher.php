<?php

declare(strict_types=1);

namespace Fluxx\Operations;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Fluxx\Entity\Enum\WorkflowStepRunStatus;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;
use Fluxx\Repository\RuntimeWorkerStateRepository;
use Fluxx\Repository\WorkflowExecutionLockStoreInterface;
use Fluxx\Repository\WorkflowRunRepository;
use Fluxx\Repository\WorkflowStepRunRepository;
use Fluxx\Settings\RuntimeSettingsManager;

/**
 * Closes workflow runs that can no longer make progress.
 *
 * A run becomes an orphan when its worker died (crash, OOM, container loss)
 * or its messages were consumed elsewhere: it stays `running`, `retrying` or
 * `pending` forever while nothing executes it anymore. This operation finalizes
 * those runs as failed so the delivered steps become no-ops and the run fades
 * out of the live views.
 *
 * A run is skipped when a processing worker still claims it (fresh heartbeat
 * between steps, or a step started recently and still mid-flight), when its
 * last recorded activity is younger than the staleness window, or when the run
 * executes synchronously in-process (host request or CLI, no worker to detect).
 */
final readonly class OrphanRunFinisher
{
    private const DEFAULT_MAX_RUNS = 50;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private WorkflowRunRepository $workflowRunRepository,
        private WorkflowStepRunRepository $workflowStepRunRepository,
        private WorkflowExecutionLockStoreInterface $workflowExecutionLockRepository,
        private RuntimeWorkerStateRepository $runtimeWorkerStateRepository,
        private RuntimeSettingsManager $runtimeSettingsManager,
    ) {
    }

    /**
     * @return list<array{runId: string, workflowCode: string, failedSteps: int, staleSeconds: int}>
     */
    public function finishOrphanRuns(?int $staleSeconds = null, int $maxRuns = self::DEFAULT_MAX_RUNS): array
    {
        $staleSeconds ??= max($this->runtimeSettingsManager->get()->staleLockTimeoutSeconds, 1);
        $now = new DateTimeImmutable();
        $staleThreshold = $now->modify(sprintf('-%d seconds', $staleSeconds));

        $candidates = $this->workflowRunRepository->findActiveRuns(max($maxRuns, 1));

        if ($candidates === []) {
            return [];
        }

        $processingRunIds = $this->runtimeWorkerStateRepository->findProcessingRunIds(
            array_map(static fn (WorkflowRun $run): string => $run->runId(), $candidates),
            $staleThreshold,
            $staleThreshold,
        );

        $processed = [];
        $finished = [];

        foreach ($candidates as $run) {
            if ($run->isSynchronous()) {
                continue;
            }

            if (in_array($run->runId(), $processingRunIds, true)) {
                continue;
            }

            $stepRuns = $this->workflowStepRunRepository->findByWorkflowRunsGrouped([$run])[$run->runId()] ?? [];

            if ($this->resolveLastActivityAt($run, $stepRuns) >= $staleThreshold) {
                continue;
            }

            $failedSteps = $this->failPendingStepRuns($stepRuns, $now, $staleSeconds);
            $run->markFailed(
                sprintf(
                    'Execution interrupted automatically: no worker activity for %d second(s).',
                    $staleSeconds,
                ),
                $now,
            );

            $lock = $this->workflowExecutionLockRepository->findActiveByOwnerRunId($run->runId());
            $lock?->release('orphan_run_finalized');

            $finished[] = [
                'runId' => $run->runId(),
                'workflowCode' => $run->workflowName(),
                'failedSteps' => $failedSteps,
                'staleSeconds' => $staleSeconds,
            ];
            $processed[] = $run;
        }

        if ($processed !== []) {
            $this->entityManager->flush();
        }

        return $finished;
    }

    /**
     * @param list<WorkflowStepRun> $stepRuns
     */
    private function resolveLastActivityAt(WorkflowRun $run, array $stepRuns): DateTimeImmutable
    {
        $lastActivityAt = $run->startedAt() ?? $run->createdAt();

        foreach ($stepRuns as $stepRun) {
            $activityAt = $stepRun->finishedAt() ?? $stepRun->startedAt() ?? $stepRun->createdAt();

            if ($activityAt > $lastActivityAt) {
                $lastActivityAt = $activityAt;
            }
        }

        return $lastActivityAt;
    }

    /**
     * @param list<WorkflowStepRun> $stepRuns
     */
    private function failPendingStepRuns(array $stepRuns, DateTimeImmutable $finishedAt, int $staleSeconds): int
    {
        $failedSteps = 0;

        foreach ($stepRuns as $stepRun) {
            if ($stepRun->finishedAt() !== null) {
                continue;
            }

            if (!in_array($stepRun->status(), [
                WorkflowStepRunStatus::Running,
                WorkflowStepRunStatus::Relaunched,
                WorkflowStepRunStatus::Retrying,
                WorkflowStepRunStatus::Pending,
            ], true)) {
                continue;
            }

            $stepRun->markFailed(
                sprintf(
                    'Execution interrupted automatically: no worker activity for %d second(s).',
                    $staleSeconds,
                ),
                finishedAt: $finishedAt,
            );
            ++$failedSteps;
        }

        return $failedSteps;
    }
}
