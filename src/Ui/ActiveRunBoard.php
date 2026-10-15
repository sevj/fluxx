<?php

declare(strict_types=1);

namespace Fluxx\Ui;

use DateTimeImmutable;
use Fluxx\Entity\RuntimeWorkerState;
use Fluxx\Repository\RuntimeWorkerStateRepository;
use Fluxx\Repository\WorkflowRunRepository;
use Fluxx\Repository\WorkflowStepRunRepository;
use Fluxx\Settings\RuntimeSettingsManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ActiveRunBoard
{
    private const MAX_ACTIVE_RUNS = 50;
    private const MAX_ACTIVITY = 40;
    private const BUSY_WORKER_HEARTBEAT_TTL_MS = 30000;

    public function __construct(
        private WorkflowRunRepository $workflowRunRepository,
        private WorkflowStepRunRepository $workflowStepRunRepository,
        private RuntimeWorkerStateRepository $runtimeWorkerStateRepository,
        private RuntimeSettingsManager $runtimeSettingsManager,
        private ActiveRunsViewFactory $factory,
        #[Autowire('%fluxx.runtime.transport_name%')]
        private string $transportName = 'fluxx',
    ) {
    }

    public function page(): ActiveRunBoardPage
    {
        $now = new DateTimeImmutable();
        $runs = $this->workflowRunRepository->findActiveRuns(self::MAX_ACTIVE_RUNS);
        $stepRunsByRunId = $this->workflowStepRunRepository->findByWorkflowRunsGrouped($runs);
        $recentStepRuns = $this->workflowStepRunRepository->findRecentActivity(self::MAX_ACTIVITY);
        $workerStates = $this->runtimeWorkerStateRepository->findIndexedByTransportName($this->transportName);

        $staleSeconds = max($this->runtimeSettingsManager->get()->staleLockTimeoutSeconds, 1);
        $staleThreshold = $now->modify(sprintf('-%d seconds', $staleSeconds));

        [$workerNamesByRunId, $busyWorkerCount, $processingRunIds] = $this->groupWorkers($workerStates, $now, $staleThreshold);

        $rows = [];
        $runningStepCount = 0;

        foreach ($runs as $run) {
            $row = $this->factory->row(
                run: $run,
                stepRuns: $stepRunsByRunId[$run->runId()] ?? [],
                workerNames: $workerNamesByRunId[$run->runId()] ?? [],
                hasLiveWorker: in_array($run->runId(), $processingRunIds, true),
                staleSeconds: $staleSeconds,
                now: $now,
            );

            $rows[] = $row;
            $runningStepCount += count($row->currentSteps());
        }

        usort($rows, static function (ActiveRunRowView $a, ActiveRunRowView $b): int {
            $aStarted = $a->startedAt() ?? $a->createdAt();
            $bStarted = $b->startedAt() ?? $b->createdAt();

            return $bStarted <=> $aStarted;
        });

        $activities = [];

        foreach ($recentStepRuns as $stepRun) {
            $activity = $this->factory->activity($stepRun, $now);

            if ($activity !== null) {
                $activities[] = $activity;
            }
        }

        return new ActiveRunBoardPage(
            refreshedAt: $now,
            rows: $rows,
            activities: $activities,
            activeRunCount: count($rows),
            runningStepCount: $runningStepCount,
            busyWorkerCount: $busyWorkerCount,
        );
    }

    /**
     * @param array<string, RuntimeWorkerState> $workerStates
     * @return array{0: array<string, list<string>>, 1: int, 2: list<string>}
     */
    private function groupWorkers(
        array $workerStates,
        DateTimeImmutable $now,
        DateTimeImmutable $staleThreshold,
    ): array {
        $workerNamesByRunId = [];
        $busyWorkerCount = 0;
        $processingRunIds = [];

        foreach ($workerStates as $workerState) {
            if ($workerState->status() !== 'processing') {
                continue;
            }

            $heartbeatAgeMs = max(0, (int) $now->format('Uv') - (int) $workerState->lastHeartbeatAt()->format('Uv'));
            $hasFreshHeartbeat = $heartbeatAgeMs <= self::BUSY_WORKER_HEARTBEAT_TTL_MS;
            $hasRecentProcessingStart = $workerState->startedProcessingAt() !== null
                && $workerState->startedProcessingAt() >= $staleThreshold;

            if ($hasFreshHeartbeat) {
                ++$busyWorkerCount;
            }

            if ($hasFreshHeartbeat || $hasRecentProcessingStart) {
                $runId = $workerState->runId();

                if ($runId !== null) {
                    $workerNamesByRunId[$runId][] = $workerState->workerName();
                    $processingRunIds[$runId] = $runId;
                }
            }
        }

        return [$workerNamesByRunId, $busyWorkerCount, array_values($processingRunIds)];
    }
}
