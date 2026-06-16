<?php

declare(strict_types=1);

namespace Fluxx\Tests\Entity;

use Fluxx\Entity\Enum\WorkflowStepDeduplicationStatus;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkflowStepRunTest extends TestCase
{
    #[Test]
    public function it_marks_applied_idempotence_on_a_step_run(): void
    {
        $stepRun = $this->createStepRun('run-applied', 'contacts_write');

        $stepRun->markIdempotenceApplied('key-1');

        self::assertSame('key-1', $stepRun->idempotenceKey());
        self::assertSame(WorkflowStepDeduplicationStatus::Applied, $stepRun->deduplicationStatus());
        self::assertNull($stepRun->deduplicatedFromStepRun());
    }

    #[Test]
    public function it_records_the_source_step_run_when_deduplicated(): void
    {
        $source = $this->createStepRun('run-source', 'contacts_write');
        $stepRun = $this->createStepRun('run-target', 'contacts_write');

        $stepRun->markDeduplicated('key-2', $source);

        self::assertSame('key-2', $stepRun->idempotenceKey());
        self::assertSame(WorkflowStepDeduplicationStatus::Deduplicated, $stepRun->deduplicationStatus());
        self::assertSame($source, $stepRun->deduplicatedFromStepRun());
    }

    #[Test]
    public function it_tracks_retry_state_and_timestamps(): void
    {
        $stepRun = $this->createStepRun('run-retry', 'contacts_write');
        $lastRetryAt = new \DateTimeImmutable('-1 minute');
        $nextRetryAt = new \DateTimeImmutable('+1 minute');

        $stepRun->scheduleRetry(
            lastRetryAt: $lastRetryAt,
            nextRetryAt: $nextRetryAt,
            errorMessage: 'temporary outage',
            errorCount: 1,
            durationMs: 250,
            memoryPeakBytes: 2048,
            errorPayload: ['category' => 'technical'],
        );

        self::assertSame(1, $stepRun->retryCount());
        self::assertSame($lastRetryAt, $stepRun->lastRetryAt());
        self::assertSame($nextRetryAt, $stepRun->nextRetryAt());
        self::assertSame('temporary outage', $stepRun->errorMessage());
        self::assertSame('technical', $stepRun->errorPayload()['category']);
    }

    private function createStepRun(string $runId, string $stepName): WorkflowStepRun
    {
        return new WorkflowStepRun(
            workflowRun: new WorkflowRun(
                runId: $runId,
                workflowName: 'contacts',
                sourceSystem: 'CSV',
                targetSystem: 'Hubspot',
                trigger: 'manual',
            ),
            stepType: 'write',
            stepName: $stepName,
            position: 1,
        );
    }
}
