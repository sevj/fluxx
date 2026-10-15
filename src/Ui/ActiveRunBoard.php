<?php

declare(strict_types=1);

namespace Fluxx\Ui;

use DateTimeImmutable;
use Fluxx\Repository\RuntimeWorkerStateRepository;
use Fluxx\Repository\WorkflowRunRepository;
use Fluxx\Repository\WorkflowStepRunRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ActiveRunBoard
{
    private const MAX_ACTIVE_RUNS = 50;
    private const MAX_ACTIVITY = 40;
    private const ACTIVE_WORKER_HEARTBEAT_TTL_MS = 30000;

    public function __construct(
        private WorkflowRunRepository $workflowRunRepository,
        private WorkflowStepRunRepository $workflowStepRunRepository,
        private RuntimeWorkerStateRepository $runtimeWorkerStateRepository,
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

        [$workerNamesByRunId, $busyWorkerCount] = $this->busyWorkers($workerStates, $now);

        $rows = [];
        $runningStepCount = 0;

        foreach ($runs as $run) {
            $row = $this->factory->row(
                $run,
                $stepRunsByRunId[$run->runId()] ?? [],
                $workerNamesByRunId[$run->runId()] ?? [],
                $now,
            );

            $rows[] = $row;
            $runningStepCount += count($row->currentSteps());
        }

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
     * @param array<string, \Fluxx\Entity\RuntimeWorkerState> $workerStates
     * @return array{0: array<string, list<string>>, 1: int}
     */
    private function busyWorkers(array $workerStates, DateTimeImmutable $now): array
    {
        $workerNamesByRunId = [];
        $busyWorkerCount = 0;

        foreach ($workerStates as $workerState) {
            if ($workerState->status() !== 'processing') {
                continue;
            }

            $heartbeatAgeMs = max(0, (int) $now->format('Uv') - (int) $workerState->lastHeartbeatAt()->format('Uv'));

            if ($heartbeatAgeMs > self::ACTIVE_WORKER_HEARTBEAT_TTL_MS) {
                continue;
            }

            ++$busyWorkerCount;

            $runId = $workerState->runId();

            if ($runId !== null) {
                $workerNamesByRunId[$runId][] = $workerState->workerName();
            }
        }

        return [$workerNamesByRunId, $busyWorkerCount];
    }
}
