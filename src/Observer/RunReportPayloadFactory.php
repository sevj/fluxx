<?php

declare(strict_types=1);

namespace Fluxx\Observer;

use DateTimeImmutable;
use Fluxx\Entity\Enum\WorkflowRunStatus;
use Fluxx\Entity\Enum\WorkflowStepRunStatus;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;
use Fluxx\Workflow\Error\WorkflowErrorCategory;

/**
 * Turns a run into the report the observer ingests. Only metadata travels: no records, no
 * payloads, no error message or context. The error is reduced to its category and to the
 * code the workflow chose for it.
 */
final class RunReportPayloadFactory
{
    private const ERROR_CODE_MAX_LENGTH = 120;

    /**
     * @param iterable<WorkflowStepRun> $stepRuns
     *
     * @return array<string, mixed>
     */
    public function create(WorkflowRun $run, iterable $stepRuns): array
    {
        $processedCount = 0;
        $successCount = 0;
        $errorCount = 0;
        $retryCount = 0;
        $failedStepError = null;

        // Same totals as the run page: the sum over the run's steps.
        foreach ($stepRuns as $stepRun) {
            $processedCount += $stepRun->processedCount();
            $successCount += $stepRun->successCount();
            $errorCount += $stepRun->errorCount();
            $retryCount += $stepRun->retryCount();

            if ($stepRun->status() === WorkflowStepRunStatus::Failed && $stepRun->errorPayload() !== null) {
                $failedStepError = $stepRun->errorPayload();
            }
        }

        $error = $this->hasFailed($run) ? ($run->errorPayload() ?? $failedStepError) : null;
        $relaunchOf = $run->relaunchMetadata()['original_run_id'] ?? null;

        return [
            'runId' => $run->runId(),
            'workflowName' => $run->workflowName(),
            'sourceSystem' => $run->sourceSystem(),
            'targetSystem' => $run->targetSystem(),
            'trigger' => $run->trigger(),
            'status' => $run->status()->value,
            'batchId' => $run->batchId(),
            'createdAt' => $run->createdAt()->format(DATE_ATOM),
            'startedAt' => $run->startedAt()?->format(DATE_ATOM),
            'finishedAt' => $run->finishedAt()?->format(DATE_ATOM),
            'processedCount' => $processedCount,
            'successCount' => $successCount,
            'errorCount' => $errorCount,
            'durationMs' => self::durationMs($run->startedAt(), $run->finishedAt()),
            'retryCount' => $retryCount,
            'errorCategory' => self::errorCategory($error),
            'errorCode' => self::errorCode($error),
            'warningCount' => $run->warningCount(),
            'relaunchOf' => is_string($relaunchOf) && $relaunchOf !== '' ? $relaunchOf : null,
        ];
    }

    private function hasFailed(WorkflowRun $run): bool
    {
        return $run->status() === WorkflowRunStatus::Failed || $run->status() === WorkflowRunStatus::PartiallyFailed;
    }

    private static function durationMs(?DateTimeImmutable $startedAt, ?DateTimeImmutable $finishedAt): ?int
    {
        if ($startedAt === null || $finishedAt === null) {
            return null;
        }

        return max(0, intdiv((int) $finishedAt->format('Uu') - (int) $startedAt->format('Uu'), 1000));
    }

    /**
     * @param array<string, mixed>|null $error
     */
    private static function errorCategory(?array $error): ?string
    {
        $category = WorkflowErrorCategory::tryFrom((string) ($error['category'] ?? ''));

        return $category?->value;
    }

    /**
     * @param array<string, mixed>|null $error
     */
    private static function errorCode(?array $error): ?string
    {
        $code = $error['code'] ?? null;

        if (!is_string($code) || trim($code) === '') {
            return null;
        }

        return mb_substr(trim($code), 0, self::ERROR_CODE_MAX_LENGTH);
    }
}
