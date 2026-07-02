<?php

declare(strict_types=1);

namespace Fluxx\Reporting;

use DateTimeImmutable;

final readonly class DailyWorkflowRecap
{
    /**
     * @param array<string, int> $statusCounts
     * @param array<string, array{total: int, statuses: array<string, int>, errors: int}> $workflowCounts
     * @param list<array{runId: string, workflow: string, status: string, createdAt: DateTimeImmutable, error: ?string, steps: list<array{code: string, type: string, status: string, processed: int, success: int, errors: int, durationMs: ?int, memoryPeakBytes: ?int, retries: int, startedAt: ?DateTimeImmutable, finishedAt: ?DateTimeImmutable, error: ?string, errorDetails: list<string>}>}> $erroredRuns
     */
    public function __construct(
        private DateTimeImmutable $from,
        private DateTimeImmutable $to,
        private array $statusCounts,
        private array $workflowCounts,
        private array $erroredRuns,
        private int $processedCount,
        private int $successCount,
        private int $errorCount,
        private ?int $averageDurationMs,
        private ?int $maxDurationMs,
    ) {
    }

    public function from(): DateTimeImmutable { return $this->from; }

    public function to(): DateTimeImmutable { return $this->to; }

    /** @return array<string, int> */
    public function statusCounts(): array { return $this->statusCounts; }

    /** @return array<string, array{total: int, statuses: array<string, int>, errors: int}> */
    public function workflowCounts(): array { return $this->workflowCounts; }

    /** @return list<array{runId: string, workflow: string, status: string, createdAt: DateTimeImmutable, error: ?string, steps: list<array{code: string, type: string, status: string, processed: int, success: int, errors: int, durationMs: ?int, memoryPeakBytes: ?int, retries: int, startedAt: ?DateTimeImmutable, finishedAt: ?DateTimeImmutable, error: ?string, errorDetails: list<string>}>}> */
    public function erroredRuns(): array { return $this->erroredRuns; }

    public function totalRuns(): int { return array_sum($this->statusCounts); }

    public function processedCount(): int { return $this->processedCount; }

    public function successCount(): int { return $this->successCount; }

    public function errorCount(): int { return $this->errorCount; }

    public function averageDurationMs(): ?int { return $this->averageDurationMs; }

    public function maxDurationMs(): ?int { return $this->maxDurationMs; }
}
