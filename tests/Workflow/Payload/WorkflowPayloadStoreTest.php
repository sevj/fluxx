<?php

declare(strict_types=1);

namespace Fluxx\Tests\Workflow\Payload;

use Doctrine\ORM\EntityManagerInterface;
use Fluxx\Entity\WorkflowPayload;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;
use Fluxx\Workflow\Payload\WorkflowPayloadStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

final class WorkflowPayloadStoreTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;

    private WorkflowPayloadStore $store;

    private WorkflowRun $run;

    private WorkflowStepRun $sourceStepRun;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->store = new WorkflowPayloadStore($this->entityManager);

        $this->run = new WorkflowRun('run-1', 'sync_contacts', 'crm', 'erp', 'manual');
        $this->sourceStepRun = new WorkflowStepRun($this->run, 'fetch', 'fetch_contacts', 1);
    }

    #[Test]
    public function it_round_trips_records_through_compression_and_encoding(): void
    {
        $records = [
            ['id' => 1, 'name' => 'Ada Lovelace', 'email' => 'ada@example.com'],
            ['id' => 2, 'name' => 'Alan Turing', 'email' => 'alan@example.com'],
        ];
        $metadata = ['source' => 'crm', 'exported_at' => '2026-09-14T12:00:00+00:00'];

        $persistedPayload = null;
        $this->entityManager
            ->expects(self::once())
            ->method('persist')
            ->willReturnCallback(function (WorkflowPayload $payload) use (&$persistedPayload): void {
                $persistedPayload = $payload;
            });

        $stored = $this->store->storeStepInput(
            workflowRun: $this->run,
            sourceStepRun: $this->sourceStepRun,
            targetStepType: 'transform',
            targetStepName: 'transform_contacts',
            records: $records,
            recordCount: 2,
            sequence: 1,
            metadata: $metadata,
        );

        self::assertSame($persistedPayload, $stored);
        self::assertSame('json', $stored->format());
        self::assertSame('gzip', $stored->compression());
        self::assertSame('database', $stored->storageMode());
        self::assertSame(2, $stored->recordCount());
        self::assertSame($metadata, $stored->metadata());
        self::assertSame(hash('sha256', json_encode([
            'version' => 1,
            'workflow_code' => 'sync_contacts',
            'run_id' => 'run-1',
            'target_step_type' => 'transform',
            'target_step_code' => 'transform_contacts',
            'records' => $records,
            'metadata' => $metadata,
        ], JSON_THROW_ON_ERROR)), $stored->contentHash());
        self::assertGreaterThan(0, $stored->rawSize());
        self::assertNotSame($stored->rawSize(), $stored->storedSize());

        $loaded = $this->store->load($stored);

        self::assertSame($records, $loaded['records']);
        self::assertSame($metadata, $loaded['metadata']);
        self::assertSame('transform', $loaded['target_step_type']);
        self::assertSame('transform_contacts', $loaded['target_step_code']);
        self::assertSame('run-1', $loaded['run_id']);
        self::assertSame(1, $loaded['version']);
    }

    #[Test]
    public function it_rejects_a_tampered_compressed_payload(): void
    {
        $original = $this->store->storeStepInput(
            workflowRun: $this->run,
            sourceStepRun: $this->sourceStepRun,
            targetStepType: 'transform',
            targetStepName: 'transform_contacts',
            records: [['id' => 1]],
            recordCount: 1,
        );

        $tampered = new WorkflowPayload(
            workflowRun: $this->run,
            sourceStepRun: $this->sourceStepRun,
            targetStepType: $original->targetStepType(),
            targetStepName: $original->targetStepName(),
            sequence: $original->sequence(),
            format: $original->format(),
            compression: $original->compression(),
            storageMode: $original->storageMode(),
            content: base64_encode('not-a-valid-gzip-stream'),
            contentHash: $original->contentHash(),
            recordCount: $original->recordCount(),
            rawSize: $original->rawSize(),
            storedSize: $original->storedSize(),
            metadata: $original->metadata(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to decompress workflow payload.');

        $this->store->load($tampered);
    }
}
