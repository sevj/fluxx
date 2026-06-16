<?php

declare(strict_types=1);

namespace Fluxx\Tests\Ui;

use DateTimeImmutable;
use Fluxx\Ui\StepRunDetailView;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StepRunDetailViewTest extends TestCase
{
    #[Test]
    public function it_exposes_lock_and_deduplication_fields(): void
    {
        $view = new StepRunDetailView(
            workflowCode: 'contacts',
            workflowName: 'Contacts',
            workflowSourceSystem: 'CSV',
            workflowTargetSystem: 'Hubspot',
            runId: 'run-1',
            workflowStatus: 'completed',
            workflowLockKey: 'contacts:CSV:Hubspot',
            workflowLockScope: 'workflow_source_target',
            workflowRelaunchMode: 'step',
            workflowOriginalRunId: 'run-0',
            workflowRestartStepCode: 'write_contacts',
            trigger: 'manual',
            stepType: 'write',
            stepTypeLabel: 'Write',
            stepTypeTone: 'write',
            stepTypeToneStyle: null,
            stepCode: 'write_contacts',
            stepName: 'Write Contacts',
            stepStatus: 'completed',
            processedCount: 3,
            successCount: 3,
            errorCount: 0,
            durationMs: 90,
            memoryPeakBytes: 1024,
            retryCount: 2,
            lastRetryAt: new DateTimeImmutable('-5 minutes'),
            nextRetryAt: new DateTimeImmutable('+5 minutes'),
            createdAt: new DateTimeImmutable(),
            startedAt: null,
            finishedAt: null,
            errorMessage: null,
            errorCategory: null,
            errorCode: null,
            idempotenceKey: 'detail-key',
            deduplicationStatus: 'deduplicated',
            deduplicatedFromRunId: 'run-0',
            deduplicatedFromStepCode: 'write_contacts',
            metadata: [],
            inputPayloads: [],
            outputPayloads: [],
        );

        self::assertSame('contacts:CSV:Hubspot', $view->workflowLockKey());
        self::assertSame('workflow_source_target', $view->workflowLockScope());
        self::assertSame('step', $view->workflowRelaunchMode());
        self::assertSame('run-0', $view->workflowOriginalRunId());
        self::assertSame('write_contacts', $view->workflowRestartStepCode());
        self::assertSame(2, $view->retryCount());
        self::assertNotNull($view->lastRetryAt());
        self::assertNotNull($view->nextRetryAt());
        self::assertSame('detail-key', $view->idempotenceKey());
        self::assertSame('deduplicated', $view->deduplicationStatus());
        self::assertSame('run-0', $view->deduplicatedFromRunId());
        self::assertSame('write_contacts', $view->deduplicatedFromStepCode());
    }
}
