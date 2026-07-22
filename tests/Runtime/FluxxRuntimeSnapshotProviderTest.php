<?php

declare(strict_types=1);

namespace Fluxx\Tests\Runtime;

use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use Fluxx\Entity\RuntimeWorkerState;
use Fluxx\Repository\RuntimeWorkerStateRepository;
use Fluxx\Repository\WorkflowExecutionLockRepository;
use Fluxx\Repository\WorkflowRunRepository;
use Fluxx\Repository\WorkflowStepRunRepository;
use Fluxx\Runtime\FluxxRuntimeSnapshotProvider;
use Fluxx\StepType\StepTypeRegistry;
use Fluxx\Workflow\Message\RunWorkflowStepMessage;
use Fluxx\Workflow\SynchronizationRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final class FluxxRuntimeSnapshotProviderTest extends TestCase
{
    #[Test]
    public function it_marks_a_stale_processing_worker_as_offline(): void
    {
        $provider = $this->createProvider();
        $workerState = new RuntimeWorkerState(
            workerName: 'connector-sipperec-64cf9c54c8-l85vk-messenger-fluxx_01',
            transportName: 'fluxx',
            host: 'connector-sipperec-64cf9c54c8-l85vk',
            pid: 406550,
            receiverName: 'fluxx',
        );
        $workerState->markProcessing(
            messageClass: RunWorkflowStepMessage::class,
            transportMessageId: '1782885153171-0',
            workflowCode: 'insee_organisation_webhook',
            runId: '31a739ed45be0b00fa34764ba8e17fb5',
            stepCode: 'insee_fetch',
            startedAt: new DateTimeImmutable('2026-07-22 13:39:39'),
            memoryBytes: 14 * 1024 * 1024,
        );

        $row = $this->buildWorkerRow(
            $provider,
            [
                'name' => 'connector-sipperec-64cf9c54c8-l85vk-messenger-fluxx_01',
                'state' => 'idle',
                'pendingCount' => 1,
                'idleMs' => 2470200,
                'lastSeenAt' => '2026-07-22T14:20:49+00:00',
            ],
            $workerState,
            new DateTimeImmutable('2026-07-22 14:20:49'),
        );

        self::assertSame('offline', $row['state']);
        self::assertNull($row['currentMessageClass']);
        self::assertNull($row['currentTransportMessageId']);
        self::assertNull($row['workflowCode']);
        self::assertNull($row['runId']);
        self::assertNull($row['stepCode']);
        self::assertNull($row['processingStartedAt']);
        self::assertNull($row['processingDurationMs']);
        self::assertSame('connector-sipperec-64cf9c54c8-l85vk', $row['host']);
        self::assertSame(406550, $row['pid']);
    }

    #[Test]
    public function it_keeps_a_fresh_processing_worker_visible_as_processing(): void
    {
        $provider = $this->createProvider();
        $workerState = new RuntimeWorkerState(
            workerName: 'connector-sipperec-current-messenger-fluxx_00',
            transportName: 'fluxx',
            host: 'connector-sipperec-current',
            pid: 363,
            receiverName: 'fluxx',
        );
        $workerState->markProcessing(
            messageClass: RunWorkflowStepMessage::class,
            transportMessageId: '1782885159999-0',
            workflowCode: 'insee_organisation_webhook',
            runId: 'run-current',
            stepCode: 'insee_fetch',
            startedAt: new DateTimeImmutable('2026-07-22 14:20:40'),
            memoryBytes: 8 * 1024 * 1024,
        );

        $row = $this->buildWorkerRow(
            $provider,
            [
                'name' => 'connector-sipperec-current-messenger-fluxx_00',
                'state' => 'active',
                'pendingCount' => 1,
                'idleMs' => 1000,
                'lastSeenAt' => '2026-07-22T14:20:44+00:00',
            ],
            $workerState,
            new DateTimeImmutable('2026-07-22 14:20:45'),
        );

        self::assertSame('processing', $row['state']);
        self::assertSame(RunWorkflowStepMessage::class, $row['currentMessageClass']);
        self::assertSame('1782885159999-0', $row['currentTransportMessageId']);
        self::assertSame('insee_organisation_webhook', $row['workflowCode']);
        self::assertSame('run-current', $row['runId']);
        self::assertSame('insee_fetch', $row['stepCode']);
        self::assertSame(5000, $row['processingDurationMs']);
    }

    #[Test]
    public function it_marks_a_stale_redis_consumer_without_worker_state_as_offline(): void
    {
        $provider = $this->createProvider();

        $row = $this->buildWorkerRow(
            $provider,
            [
                'name' => 'connector-sipperec-old-messenger-fluxx_09',
                'state' => 'idle',
                'pendingCount' => 0,
                'idleMs' => 180000,
                'lastSeenAt' => '2026-07-22T14:17:45+00:00',
            ],
            null,
            new DateTimeImmutable('2026-07-22 14:20:45'),
        );

        self::assertSame('offline', $row['state']);
        self::assertNull($row['currentMessageClass']);
        self::assertNull($row['processingDurationMs']);
    }

    #[Test]
    public function it_marks_a_redis_consumer_without_fresh_worker_heartbeat_as_offline_even_if_redis_looks_recent(): void
    {
        $provider = $this->createProvider();

        $row = $this->buildWorkerRow(
            $provider,
            [
                'name' => 'connector-sipperec-ghost-messenger-fluxx_11',
                'state' => 'active',
                'pendingCount' => 0,
                'idleMs' => 1000,
                'lastSeenAt' => '2026-07-22T14:20:44+00:00',
            ],
            null,
            new DateTimeImmutable('2026-07-22 14:20:45'),
        );

        self::assertSame('offline', $row['state']);
        self::assertNull($row['currentMessageClass']);
        self::assertNull($row['processingDurationMs']);
    }

    private function createProvider(): FluxxRuntimeSnapshotProvider
    {
        $registry = $this->createMock(ManagerRegistry::class);

        return new FluxxRuntimeSnapshotProvider(
            fluxxTransportDsn: 'redis://localhost/messages/fluxx',
            transportSerializer: $this->createMock(SerializerInterface::class),
            runtimeWorkerStateRepository: new RuntimeWorkerStateRepository($registry),
            workflowExecutionLockRepository: new WorkflowExecutionLockRepository($registry),
            workflowRunRepository: new WorkflowRunRepository($registry),
            workflowStepRunRepository: new WorkflowStepRunRepository($registry),
            registry: new SynchronizationRegistry([]),
            stepTypeRegistry: new StepTypeRegistry([]),
        );
    }

    private function buildWorkerRow(
        FluxxRuntimeSnapshotProvider $provider,
        array $redisWorker,
        ?RuntimeWorkerState $workerState,
        DateTimeImmutable $refreshedAt,
    ): array {
        $workflowDefinitions = [];
        $callable = \Closure::bind(
            static function (
                FluxxRuntimeSnapshotProvider $provider,
                array $redisWorker,
                ?RuntimeWorkerState $workerState,
                array &$workflowDefinitions,
                DateTimeImmutable $refreshedAt,
            ): array {
                return $provider->buildWorkerRow($redisWorker, $workerState, $workflowDefinitions, $refreshedAt);
            },
            null,
            FluxxRuntimeSnapshotProvider::class,
        );

        return $callable($provider, $redisWorker, $workerState, $workflowDefinitions, $refreshedAt);
    }
}
