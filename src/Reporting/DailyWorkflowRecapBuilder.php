<?php

declare(strict_types=1);

namespace Fluxx\Reporting;

use DateTimeImmutable;
use Fluxx\Entity\Enum\WorkflowRunStatus;
use Fluxx\Entity\Enum\WorkflowStepRunStatus;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;
use Fluxx\Repository\WorkflowRunRepository;
use Fluxx\Repository\WorkflowStepRunRepository;
use function in_array;
use function max;

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
        $stepRuns = $this->workflowStepRunRepository->findByWorkflowRunsGrouped($runs);
        $statusCounts = [];
        $workflowCounts = [];
        $erroredRuns = [];
        $processedCount = 0;
        $successCount = 0;
        $errorCount = 0;
        $durationTotal = 0;
        $durationCount = 0;
        $maxDurationMs = null;

        foreach ($runs as $run) {
            $status = $run->status()->value;
            $workflow = $run->workflowName();
            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
            $workflowCounts[$workflow] ??= ['total' => 0, 'statuses' => [], 'errors' => 0];
            ++$workflowCounts[$workflow]['total'];
            $workflowCounts[$workflow]['statuses'][$status] = ($workflowCounts[$workflow]['statuses'][$status] ?? 0) + 1;

            if (in_array($run->status(), [WorkflowRunStatus::Failed, WorkflowRunStatus::PartiallyFailed], true)) {
                ++$workflowCounts[$workflow]['errors'];
                $erroredRuns[] = $this->erroredRun($run, $stepRuns[$run->runId()] ?? []);
            }

            foreach ($stepRuns[$run->runId()] ?? [] as $stepRun) {
                $processedCount += $stepRun->processedCount();
                $successCount += $stepRun->successCount();
                $errorCount += $stepRun->errorCount();

                if ($stepRun->durationMs() !== null) {
                    $durationTotal += $stepRun->durationMs();
                    ++$durationCount;
                    $maxDurationMs = max($maxDurationMs ?? 0, $stepRun->durationMs());
                }
            }
        }

        return new DailyWorkflowRecap(
            from: $from,
            to: $to,
            statusCounts: $statusCounts,
            workflowCounts: $workflowCounts,
            erroredRuns: $erroredRuns,
            processedCount: $processedCount,
            successCount: $successCount,
            errorCount: $errorCount,
            averageDurationMs: $durationCount > 0 ? (int) ($durationTotal / $durationCount) : null,
            maxDurationMs: $maxDurationMs,
        );
    }

    /**
     * @param list<WorkflowStepRun> $stepRuns
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
            'steps' => array_values(array_map(
                fn (WorkflowStepRun $stepRun): array => $this->erroredStep($stepRun),
                array_filter(
                    $stepRuns,
                    static fn (WorkflowStepRun $stepRun): bool => in_array(
                        $stepRun->status(),
                        [WorkflowStepRunStatus::Failed, WorkflowStepRunStatus::Retrying, WorkflowStepRunStatus::Cancelled],
                        true,
                    ),
                ),
            )),
        ];
    }

    /**
     * @return array{code: string, type: string, status: string, processed: int, success: int, errors: int, durationMs: ?int, memoryPeakBytes: ?int, retries: int, startedAt: ?DateTimeImmutable, finishedAt: ?DateTimeImmutable, error: ?string, errorDetails: list<string>}
     */
    private function erroredStep(WorkflowStepRun $stepRun): array
    {
        return [
            'code' => $stepRun->stepName(),
            'type' => $stepRun->stepType(),
            'status' => $stepRun->status()->value,
            'processed' => $stepRun->processedCount(),
            'success' => $stepRun->successCount(),
            'errors' => $stepRun->errorCount(),
            'durationMs' => $stepRun->durationMs(),
            'memoryPeakBytes' => $stepRun->memoryPeakBytes(),
            'retries' => $stepRun->retryCount(),
            'startedAt' => $stepRun->startedAt(),
            'finishedAt' => $stepRun->finishedAt(),
            'error' => $stepRun->errorMessage(),
            'errorDetails' => $this->formatErrorPayload($stepRun->errorPayload()),
        ];
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return list<string>
     */
    private function formatErrorPayload(?array $payload): array
    {
        if ($payload === null) {
            return [];
        }

        $details = [];

        foreach ($payload as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $details[] = sprintf('%s: %s', $key, $value ?? 'null');
            }
        }

        return $details;
    }
}
