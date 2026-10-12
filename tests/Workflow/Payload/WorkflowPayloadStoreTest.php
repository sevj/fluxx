<?php

declare(strict_types=1);

namespace Fluxx\Tests\Workflow\Payload;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Fluxx\Entity\WorkflowPayload;
use Fluxx\Entity\WorkflowPayloadContent;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;
use Fluxx\Workflow\Payload\WorkflowPayloadStore;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

#[AllowMockObjectsWithoutExpectations]
final class WorkflowPayloadStoreTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;

    private EntityRepository&MockObject $contentRepository;

    private WorkflowPayloadStore $store;

    private WorkflowRun $run;

    private WorkflowStepRun $sourceStepRun;

    private ?WorkflowPayloadContent $findOneByResult = null;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->contentRepository = $this->createMock(EntityRepository::class);
        $this->store = new WorkflowPayloadStore($this->entityManager);

        $this->run = new WorkflowRun('run-1', 'sync_contacts', 'crm', 'erp', 'manual');
        $this->sourceStepRun = new WorkflowStepRun($this->run, 'fetch', 'fetch_contacts', 1);

        $this->contentRepository->method('findOneBy')->willReturnCallback(
            fn (): ?WorkflowPayloadContent => $this->findOneByResult,
        );

        $this->entityManager
            ->method('getRepository')
            ->willReturnCallback(fn (string $class): EntityRepository =>
                $class === WorkflowPayloadContent::class ? $this->contentRepository : $this->createMock(EntityRepository::class),
        );
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
            ->method('persist')
            ->willReturnCallback(function (object $entity) use (&$persistedPayload): void {
                if ($entity instanceof WorkflowPayload) {
                    $persistedPayload = $entity;
                }
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
    public function it_deduplicates_content_across_downstreams_with_identical_records(): void
    {
        $records = [['id' => 1, 'name' => 'Ada Lovelace']];
        $metadata = ['source' => 'crm'];

        $this->findOneByResult = null;

        $first = $this->store->storeStepInput(
            workflowRun: $this->run,
            sourceStepRun: $this->sourceStepRun,
            targetStepType: 'transform',
            targetStepName: 'transform_contacts',
            records: $records,
            recordCount: 1,
            metadata: $metadata,
        );

        $sharedContent = $first->payloadContent();
        $this->findOneByResult = $sharedContent;

        $second = $this->store->storeStepInput(
            workflowRun: $this->run,
            sourceStepRun: $this->sourceStepRun,
            targetStepType: 'write',
            targetStepName: 'write_contacts',
            records: $records,
            recordCount: 1,
            metadata: $metadata,
        );

        self::assertSame($sharedContent, $second->payloadContent());
        self::assertSame($first->contentHash(), $second->contentHash());
        self::assertSame($first->content(), $second->content());
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

        $tamperedContent = new WorkflowPayloadContent(
            workflowRun: $this->run,
            sourceStepRun: $this->sourceStepRun,
            contentHash: $original->contentHash(),
            format: 'json',
            compression: 'gzip',
            storageMode: 'database',
            content: base64_encode('not-a-valid-gzip-stream'),
            recordCount: $original->recordCount(),
            rawSize: $original->rawSize(),
            storedSize: $original->storedSize(),
        );

        $tampered = new WorkflowPayload(
            workflowRun: $this->run,
            sourceStepRun: $this->sourceStepRun,
            payloadContent: $tamperedContent,
            targetStepType: $original->targetStepType(),
            targetStepName: $original->targetStepName(),
            sequence: $original->sequence(),
            metadata: $original->metadata(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to decompress workflow payload.');

        $this->store->load($tampered);
    }
}
