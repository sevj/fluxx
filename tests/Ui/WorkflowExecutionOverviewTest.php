<?php

declare(strict_types=1);

namespace Fluxx\Tests\Ui;

use DateTimeImmutable;
use Fluxx\Ui\WorkflowExecutionOverview;
use Fluxx\Ui\WorkflowExecutionStepOverview;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkflowExecutionOverviewTest extends TestCase
{
    #[Test]
    public function it_exposes_lock_and_deduplication_information(): void
    {
        $step = new WorkflowExecutionStepOverview(
            type: 'write',
            typeLabel: 'Write',
            typeTone: 'write',
            typeToneStyle: null,
            code: 'write_contacts',
            name: 'Write Contacts',
            status: 'completed',
            processedCount: 5,
            successCount: 5,
            errorCount: 0,
            durationMs: 120,
            memoryPeakBytes: 1024,
            idempotenceKey: 'dup-key',
            deduplicationStatus: 'deduplicated',
            deduplicatedFromRunId: 'run-0',
        );
        $overview = new WorkflowExecutionOverview(
            runId: 'run-1',
            trigger: 'manual',
            status: 'running',
            lockKey: 'contacts:CSV:Hubspot',
            lockScope: 'workflow_source_target',
            relaunchMode: 'step',
            originalRunId: 'run-0',
            restartStepCode: 'write_contacts',
            createdAt: new DateTimeImmutable(),
            startedAt: null,
            finishedAt: null,
            errorMessage: null,
            errorCategory: null,
            steps: [$step],
            stepMap: ['write_contacts' => $step],
        );

        self::assertSame('contacts:CSV:Hubspot', $overview->lockKey());
        self::assertSame('workflow_source_target', $overview->lockScope());
        self::assertSame('step', $overview->relaunchMode());
        self::assertSame('run-0', $overview->originalRunId());
        self::assertSame('write_contacts', $overview->restartStepCode());
        self::assertSame('dup-key', $step->idempotenceKey());
        self::assertSame('deduplicated', $step->deduplicationStatus());
        self::assertSame('run-0', $step->deduplicatedFromRunId());
    }
}
