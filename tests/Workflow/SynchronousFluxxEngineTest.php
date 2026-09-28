<?php

declare(strict_types=1);

namespace Fluxx\Tests\Workflow;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Fluxx\Entity\Enum\WorkflowRunStatus;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;
use Fluxx\Repository\FluxxSettingLookupInterface;
use Fluxx\Repository\WorkflowPayloadLookupInterface;
use Fluxx\Repository\WorkflowRunLookupInterface;
use Fluxx\Settings\RuntimeSettingsManager;
use Fluxx\Tests\Fixture\InMemoryStepRunLookup;
use Fluxx\Workflow\Context\WorkflowContext;
use Fluxx\Workflow\Context\WorkflowContextFactory;
use Fluxx\Workflow\Error\WorkflowErrorPayloadFactory;
use Fluxx\Workflow\Lock\WorkflowExecutionLockManagerInterface;
use Fluxx\Workflow\Payload\WorkflowPayloadStoreInterface;
use Fluxx\Workflow\Runtime\FluxxRuntime;
use Fluxx\Workflow\Runtime\WorkflowCancellationSynchronizer;
use Fluxx\Workflow\Runtime\WorkflowIdempotenceResolver;
use Fluxx\Workflow\Runtime\WorkflowRetryScheduler;
use Fluxx\Workflow\Runtime\WorkflowRunCompletionDecider;
use Fluxx\Workflow\Result\WorkflowStepResult;
use Fluxx\Workflow\Step\ExecutableWorkflowStepInterface;
use Fluxx\Workflow\Step\WorkflowStepInput;
use Fluxx\Workflow\SynchronizationRegistry;
use Fluxx\Workflow\SynchronousFluxxEngine;
use Fluxx\Workflow\WorkflowDefinition;
use Fluxx\Workflow\WorkflowInterface;
use Fluxx\Workflow\WorkflowStepDefinition;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[AllowMockObjectsWithoutExpectations]
final class SynchronousFluxxEngineTest extends TestCase
{
    private WorkflowRunLookupInterface&MockObject $workflowRunRepository;
    private InMemoryStepRunLookup $stepRunLookup;
    private WorkflowPayloadLookupInterface&MockObject $workflowPayloadRepository;
    private WorkflowPayloadStoreInterface&MockObject $workflowPayloadStore;
    private WorkflowExecutionLockManagerInterface&MockObject $workflowExecutionLockManager;
    private MessageBusInterface&MockObject $messageBus;
    private EntityManagerInterface&MockObject $entityManager;

    /** @var array<string, WorkflowRun> */
    private array $persistedRuns = [];

    protected function setUp(): void
    {
        $this->workflowRunRepository = $this->createMock(WorkflowRunLookupInterface::class);
        $this->stepRunLookup = new InMemoryStepRunLookup();
        $this->workflowPayloadRepository = $this->createMock(WorkflowPayloadLookupInterface::class);
        $this->workflowPayloadStore = $this->createMock(WorkflowPayloadStoreInterface::class);
        $this->workflowExecutionLockManager = $this->createMock(WorkflowExecutionLockManagerInterface::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->persistedRuns = [];
        $this->entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof WorkflowRun) {
                $this->persistedRuns[$entity->runId()] = $entity;
            }
            if ($entity instanceof WorkflowStepRun) {
                $this->stepRunLookup->add($entity);
            }
        });
        $this->entityManager->method('flush')->willReturnCallback(static function (): void {});

        $this->workflowRunRepository->method('findOneByRunId')->willReturnCallback(function (string $runId): ?WorkflowRun {
            return $this->persistedRuns[$runId] ?? null;
        });

        $txActive = false;
        $this->entityManager->method('beginTransaction')->willReturnCallback(function () use (&$txActive): void {
            $txActive = true;
        });
        $this->entityManager->method('commit')->willReturnCallback(function () use (&$txActive): void {
            $txActive = false;
        });
        $this->entityManager->method('rollback')->willReturnCallback(function () use (&$txActive): void {
            $txActive = false;
        });

        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturnCallback(static function () use (&$txActive): bool {
            return $txActive;
        });
        $this->entityManager->method('getConnection')->willReturn($connection);

        $this->workflowPayloadRepository->method('findByWorkflowRunAndTargetStepNameOrdered')->willReturn([]);
        $this->workflowPayloadRepository->method('findBySourceStepRunOrdered')->willReturn([]);
    }

    #[Test]
    public function it_runs_a_single_step_workflow_synchronously_and_returns_leaf_records(): void
    {
        $records = [['id' => 1, 'siret' => '21750001600015']];
        $handler = new StubStepHandler(new WorkflowStepResult(records: $records, processedCount: 1, successCount: 1));
        $engine = $this->buildEngine($this->registry($handler));

        $result = $engine->runWithResult('fixture');

        self::assertNotEmpty($result->runId);
        self::assertSame($records, $result->records());
    }

    #[Test]
    public function it_runs_a_multi_step_workflow_and_returns_only_leaf_records(): void
    {
        $fetchRecords = [['id' => 1]];
        $writeRecords = [['id' => 1, 'enriched' => true]];
        $fetchHandler = new StubStepHandler(new WorkflowStepResult(records: $fetchRecords, processedCount: 1, successCount: 1));
        $writeHandler = new StubResultStep(new WorkflowStepResult(records: $writeRecords, processedCount: 1, successCount: 1));

        $definition = new WorkflowDefinition(
            code: 'fixture',
            name: 'Fixture',
            sourceSystem: 'src',
            targetSystem: 'tgt',
            steps: [
                new WorkflowStepDefinition('fetch', 'Fetch', 'read', $fetchHandler),
                new WorkflowStepDefinition('write', 'Write', 'write', $writeHandler, ['fetch']),
            ],
        );
        $engine = $this->buildEngine($this->registryFromDefinition($definition));

        $result = $engine->runWithResult('fixture');

        self::assertSame($writeRecords, $result->records());
    }

    #[Test]
    public function run_returns_only_run_id(): void
    {
        $handler = new StubStepHandler(new WorkflowStepResult(records: [['id' => 1]], processedCount: 1, successCount: 1));
        $engine = $this->buildEngine($this->registry($handler));

        $runId = $engine->run('fixture');

        self::assertNotEmpty($runId);
    }

    #[Test]
    public function it_returns_associative_record_from_leaf(): void
    {
        $record = ['item' => ['siret' => '21750001600015'], 'hubspotId' => '2001'];
        $handler = new StubStepHandler(new WorkflowStepResult(records: $record, processedCount: 1, successCount: 1));
        $engine = $this->buildEngine($this->registry($handler));

        $result = $engine->runWithResult('fixture');

        self::assertSame([$record], $result->records());
    }

    #[Test]
    public function it_marks_run_failed_releases_lock_and_rethrows_on_step_failure(): void
    {
        $handler = new FailingStepHandler(new RuntimeException('boom'));
        $engine = $this->buildEngine($this->registry($handler));

        $this->workflowExecutionLockManager->expects(self::once())->method('acquire');
        $this->workflowExecutionLockManager->expects(self::once())
            ->method('releaseForRun')
            ->with(
                self::callback(static fn (WorkflowRun $run): bool => $run->status() === WorkflowRunStatus::Failed),
                'failed',
            );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        try {
            $engine->runWithResult('fixture');
        } finally {
            $persistedRun = $this->persistedRuns[array_key_first($this->persistedRuns)] ?? null;
            self::assertInstanceOf(WorkflowRun::class, $persistedRun);
            self::assertSame(WorkflowRunStatus::Failed, $persistedRun->status());
            self::assertSame('boom', $persistedRun->errorMessage());
        }
    }

    private function buildEngine(SynchronizationRegistry $registry): SynchronousFluxxEngine
    {
        $cancellationSynchronizer = new WorkflowCancellationSynchronizer($this->workflowRunRepository);
        $settingsLookup = $this->createMock(FluxxSettingLookupInterface::class);
        $settingsLookup->method('findValue')->willReturn(null);
        $runtimeSettingsManager = new RuntimeSettingsManager($settingsLookup, 1800, 120, 60, 300, 10);
        $retryScheduler = new WorkflowRetryScheduler($this->messageBus, $runtimeSettingsManager);
        $idempotenceResolver = new WorkflowIdempotenceResolver(
            $this->stepRunLookup,
            $this->workflowPayloadRepository,
            $this->workflowPayloadStore,
        );

        $runtime = new FluxxRuntime(
            registry: $registry,
            entityManager: $this->entityManager,
            workflowRunRepository: $this->workflowRunRepository,
            workflowStepRunRepository: $this->stepRunLookup,
            workflowPayloadRepository: $this->workflowPayloadRepository,
            workflowPayloadStore: $this->workflowPayloadStore,
            workflowContextFactory: new WorkflowContextFactory(),
            workflowExecutionLockManager: $this->workflowExecutionLockManager,
            workflowErrorPayloadFactory: new WorkflowErrorPayloadFactory(),
            workflowRunCompletionDecider: new WorkflowRunCompletionDecider(),
            cancellationSynchronizer: $cancellationSynchronizer,
            retryScheduler: $retryScheduler,
            idempotenceResolver: $idempotenceResolver,
            maxPayloadRecords: 1000,
        );

        return new SynchronousFluxxEngine(
            registry: $registry,
            entityManager: $this->entityManager,
            workflowExecutionLockManager: $this->workflowExecutionLockManager,
            workflowErrorPayloadFactory: new WorkflowErrorPayloadFactory(),
            runtime: $runtime,
        );
    }

    private function registry(ExecutableWorkflowStepInterface $handler): SynchronizationRegistry
    {
        $definition = new WorkflowDefinition(
            code: 'fixture',
            name: 'Fixture',
            sourceSystem: 'src',
            targetSystem: 'tgt',
            steps: [
                new WorkflowStepDefinition('fetch', 'Fetch', 'read', $handler),
            ],
        );

        return $this->registryFromDefinition($definition);
    }

    private function registryFromDefinition(WorkflowDefinition $definition): SynchronizationRegistry
    {
        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->method('definition')->willReturn($definition);

        return new SynchronizationRegistry([$workflow]);
    }
}

final class StubStepHandler implements ExecutableWorkflowStepInterface
{
    public function __construct(
        private readonly WorkflowStepResult $result,
    ) {
    }

    public function code(): string
    {
        return 'fetch';
    }

    public function name(): string
    {
        return 'Fetch';
    }

    public static function staticCode(): string
    {
        return 'fetch';
    }

    public function execute(WorkflowContext $context, WorkflowStepInput $input): WorkflowStepResult
    {
        return $this->result;
    }
}

final class StubResultStep implements ExecutableWorkflowStepInterface
{
    public function __construct(
        private readonly WorkflowStepResult $result,
    ) {
    }

    public function code(): string
    {
        return 'write';
    }

    public function name(): string
    {
        return 'Write';
    }

    public static function staticCode(): string
    {
        return 'write';
    }

    public function execute(WorkflowContext $context, WorkflowStepInput $input): WorkflowStepResult
    {
        return $this->result;
    }
}

final class FailingStepHandler implements ExecutableWorkflowStepInterface
{
    public function __construct(
        private readonly \Throwable $error,
    ) {
    }

    public function code(): string
    {
        return 'fetch';
    }

    public function name(): string
    {
        return 'Fetch';
    }

    public static function staticCode(): string
    {
        return 'fetch';
    }

    public function execute(WorkflowContext $context, WorkflowStepInput $input): WorkflowStepResult
    {
        throw $this->error;
    }
}
