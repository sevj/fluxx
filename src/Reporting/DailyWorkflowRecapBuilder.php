<?php

declare(strict_types=1);

namespace Fluxx\Reporting;

use DateTimeImmutable;
use Fluxx\Entity\Enum\WorkflowRunStatus;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Repository\WorkflowRunRepository;
use Fluxx\Repository\WorkflowStepRunRepository;
use function in_array;

final readonly class DailyWorkflowRecapBuilder
{
    public function __construct(
        private WorkflowRunRepository $workflowRunRepository,
        private WorkflowStepRunRepository $workflowStepRunRepository,
    ) {
    }

    public function build(DateTimeImmutable $from, DateTimeImmutable $to): DailyWorkflowRecap
    {
        $runs = $this->workflowRunRepository->findCreatedBetween($from, $to);
        $stepSummary = $this->workflowStepRunRepository->summarizeByWorkflowRuns($runs);
        $statusCounts = [];
        $workflowCounts = [];
        $erroredRuns = [];
        $erroredRunEntities = array_values(array_filter(
            $runs,
            static fn (WorkflowRun $run): bool => in_array(
                $run->status(),
                [WorkflowRunStatus::Failed, WorkflowRunStatus::PartiallyFailed],
                true,
            ),
        ));
        $erroredStepsByRunId = $this->workflowStepRunRepository->findErroredStepRowsByWorkflowRunsGrouped($erroredRunEntities);

        foreach ($runs as $run) {
            $status = $run->status()->value;
            $workflow = $run->workflowName();
            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
            $workflowCounts[$workflow] ??= ['total' => 0, 'statuses' => [], 'errors' => 0];
            ++$workflowCounts[$workflow]['total'];
            $workflowCounts[$workflow]['statuses'][$status] = ($workflowCounts[$workflow]['statuses'][$status] ?? 0) + 1;

            if (in_array($run->status(), [WorkflowRunStatus::Failed, WorkflowRunStatus::PartiallyFailed], true)) {
                ++$workflowCounts[$workflow]['errors'];
                $erroredRuns[] = $this->erroredRun($run, $erroredStepsByRunId[$run->runId()] ?? []);
            }
        }

        return new DailyWorkflowRecap(
            from: $from,
            to: $to,
            statusCounts: $statusCounts,
            workflowCounts: $workflowCounts,
            erroredRuns: $erroredRuns,
            processedCount: $stepSummary['processedTotal'],
            successCount: $stepSummary['successTotal'],
            errorCount: $stepSummary['errorTotal'],
            averageDurationMs: $stepSummary['durationCount'] > 0 ? (int) ($stepSummary['durationTotal'] / $stepSummary['durationCount']) : null,
            maxDurationMs: $stepSummary['maxDurationMs'],
        );
    }

    /**
     * @param list<array{code: string, type: string, status: string, processed: int, success: int, errors: int, durationMs: ?int, memoryPeakBytes: ?int, retries: int, startedAt: ?DateTimeImmutable, finishedAt: ?DateTimeImmutable, error: ?string, errorDetails: list<string>}> $stepRuns
     * @return array{runId: string, workflow: string, status: string, createdAt: DateTimeImmutable, error: ?string, steps: list<array{code: string, type: string, status: string, processed: int, success: int, errors: int, durationMs: ?int, memoryPeakBytes: ?int, retries: int, startedAt: ?DateTimeImmutable, finishedAt: ?DateTimeImmutable, error: ?string, errorDetails: list<string>}>}
     */
    private function erroredRun(WorkflowRun $run, array $stepRuns): array
    {
        return [
            'runId' => $run->runId(),
            'workflow' => $run->workflowName(),
            'status' => $run->status()->value,
            'createdAt' => $run->createdAt(),
            'error' => $run->errorMessage(),
            'steps' => array_values($stepRuns),
        ];
    }
}
