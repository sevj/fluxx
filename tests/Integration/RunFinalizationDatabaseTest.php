<?php

declare(strict_types=1);

namespace Fluxx\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Fluxx\Entity\Enum\WorkflowStepRunStatus;
use Fluxx\Entity\Enum\WorkflowStepType;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;
use Fluxx\Repository\FluxxSettingLookupInterface;
use Fluxx\Repository\RuntimeWorkerStateLookupInterface;
use Fluxx\Repository\WorkflowExecutionLockRepository;
use Fluxx\Repository\WorkflowPayloadLookupInterface;
use Fluxx\Repository\WorkflowRunRepository;
use Fluxx\Repository\WorkflowStepRunRepository;
use Fluxx\Settings\RuntimeSettingsManager;
use Fluxx\Workflow\Cancellation\WorkflowCancellationService;
use Fluxx\Workflow\Check\CheckOutcome;
use Fluxx\Workflow\Check\CheckReport;
use Fluxx\Workflow\Check\CheckResult;
use Fluxx\Workflow\Check\CheckStepInterface;
use Fluxx\Workflow\Context\WorkflowContextFactory;
use Fluxx\Workflow\Error\WorkflowErrorPayloadFactory;
use Fluxx\Workflow\Lock\WorkflowExecutionLockManager;
use Fluxx\Workflow\Lock\WorkflowExecutionLockManagerInterface;
use Fluxx\Workflow\Lock\WorkflowRunAbandonmentDetector;
use Fluxx\Workflow\Payload\WorkflowPayloadStoreInterface;
use Fluxx\Workflow\Report\Severity;
use Fluxx\Workflow\Result\WorkflowStepResult;
use Fluxx\Workflow\Runtime\FluxxRuntime;
use Fluxx\Workflow\Runtime\WorkflowCancellationSynchronizer;
use Fluxx\Workflow\Runtime\WorkflowIdempotenceResolver;
use Fluxx\Workflow\Runtime\WorkflowRetryScheduler;
use Fluxx\Workflow\Runtime\WorkflowRunCompletionDecider;
use Fluxx\Workflow\SynchronizationRegistry;
use Fluxx\Workflow\WorkflowDefinition;
use Fluxx\Workflow\WorkflowInterface;
use Fluxx\Workflow\WorkflowStepDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Runs against SQLite by default. Set FLUXX_TEST_DATABASE_URL to use another database:
 * the locking test needs PostgreSQL.
 */
final class RunFinalizationDatabaseTest extends TestCase
{
    private ?string $url = null;
    private Connection $connection;
    private EntityManager $entityManager;
    private SchemaTool $schemaTool;

    /** @var list<\Doctrine\ORM\Mapping\ClassMetadata<object>> */
    private array $metadata;

    protected function setUp(): void
    {
        $this->url = getenv('FLUXX_TEST_DATABASE_URL') ?: null;

        if ($this->url === null && !extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('Requires pdo_sqlite or FLUXX_TEST_DATABASE_URL.');
        }

        $this->connection = $this->openConnection();

        $configuration = ORMSetup::createAttributeMetadataConfig([__DIR__ . '/../../src/Entity'], true);
        $configuration->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER, true));
        $configuration->enableNativeLazyObjects(true);
        $this->entityManager = new EntityManager($this->connection, $configuration);

        $this->metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $this->schemaTool = new SchemaTool($this->entityManager);
        $this->schemaTool->dropSchema($this->metadata);
        $this->schemaTool->createSchema($this->metadata);
    }

    protected function tearDown(): void
    {
        if (isset($this->schemaTool)) {
            $this->schemaTool->dropSchema($this->metadata);
            $this->connection->close();
        }
    }

    #[Test]
    public function the_locked_read_sees_a_failure_another_worker_committed_after_the_step_runs_were_loaded(): void
    {
        [$run, $failingBranch] = $this->runWithTwoRunningBranches();
        $repository = new WorkflowStepRunRepository($this->registry());

        $repository->findByWorkflowRunOrdered($run);
        $this->connection->executeStatement(
            'UPDATE fluxx_workflow_step_run SET status = :status WHERE id = :id',
            ['status' => WorkflowStepRunStatus::Failed->value, 'id' => $failingBranch->id()],
        );

        $plainRead = $this->statusesByStep($repository->findByWorkflowRunOrdered($run));
        $lockedRead = $this->statusesByStep($repository->lockRunAndFindStepRunsOrdered($run));

        self::assertSame(WorkflowStepRunStatus::Running, $plainRead['write_contacts'], 'Doctrine keeps the stale state of an already loaded step run.');
        self::assertSame(WorkflowStepRunStatus::Failed, $lockedRead['write_contacts']);
        self::assertSame(WorkflowStepRunStatus::Running, $lockedRead['write_functions']);
    }

    #[Test]
    public function two_workers_cannot_finalize_the_same_run_at_the_same_time(): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            self::markTestSkipped('Row locking is checked on PostgreSQL.');
        }

        [$run] = $this->runWithTwoRunningBranches();
        $repository = new WorkflowStepRunRepository($this->registry());
        $otherWorker = $this->openConnection();

        $this->connection->beginTransaction();
        $repository->lockRunAndFindStepRunsOrdered($run);

        $otherWorker->beginTransaction();
        $otherWorker->executeStatement("SET LOCAL lock_timeout = '200ms'");

        try {
            $otherWorker->executeQuery('SELECT id FROM fluxx_workflow_run WHERE id = :id FOR UPDATE', ['id' => $run->id()])->fetchOne();
            self::fail('The second worker got the run lock while the first one held it.');
        } catch (\Doctrine\DBAL\Exception $exception) {
            self::assertStringContainsString('lock', strtolower($exception->getMessage()));
        } finally {
            $otherWorker->rollBack();
            $otherWorker->close();
            $this->connection->rollBack();
        }
    }

    #[Test]
    public function the_run_lock_does_not_wait_for_a_worker_that_stored_payloads_of_the_same_run(): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            self::markTestSkipped('Row locking is checked on PostgreSQL.');
        }

        [$run] = $this->runWithTwoRunningBranches();
        $repository = new WorkflowStepRunRepository($this->registry());
        $otherWorker = $this->openConnection();

        $otherWorker->beginTransaction();
        $otherWorker->executeStatement(
            "INSERT INTO fluxx_workflow_step_run (workflow_run_id, step_type, step_name, position, status, deduplication_status, metadata, created_at) VALUES (:run, 'write', 'downstream', 3, 'pending', 'none', '{}', NOW())",
            ['run' => $run->id()],
        );

        $this->connection->beginTransaction();
        $this->connection->executeStatement("SET LOCAL lock_timeout = '500ms'");

        try {
            $repository->lockRunAndFindStepRunsOrdered($run);
            $this->addToAssertionCount(1);
        } finally {
            $this->connection->rollBack();
            $otherWorker->rollBack();
            $otherWorker->close();
        }
    }

    #[Test]
    public function warnings_found_by_a_parallel_check_step_are_added_to_the_ones_already_committed(): void
    {
        $run = $this->storeRun();
        $this->connection->executeStatement('UPDATE fluxx_workflow_run SET warning_count = 2 WHERE id = :id', ['id' => $run->id()]);

        $this->runCheckStep(new CheckResult('Contacts without company', CheckOutcome::Deviation, '0', '4', ['K-1']));

        self::assertSame(3, (int) $this->connection->fetchOne('SELECT warning_count FROM fluxx_workflow_run WHERE id = :id', ['id' => $run->id()]));
        self::assertSame('completed_with_warnings', $this->connection->fetchOne('SELECT status FROM fluxx_workflow_run WHERE id = :id', ['id' => $run->id()]));
    }

    #[Test]
    public function a_check_report_holding_invalid_utf8_is_stored_and_the_run_finalized(): void
    {
        $run = $this->storeRun();

        $this->runCheckStep(new CheckResult("Soci\xE9t\xE9s", CheckOutcome::Deviation, '0', '1', ["Mairie d'\xC9pinay"]));

        self::assertSame('completed_with_warnings', $this->connection->fetchOne("SELECT status FROM fluxx_workflow_step_run WHERE step_name = 'verify'"));
        self::assertSame('completed_with_warnings', $this->connection->fetchOne('SELECT status FROM fluxx_workflow_run WHERE id = :id', ['id' => $run->id()]));
        self::assertStringContainsString('Mairie', (string) $this->connection->fetchOne("SELECT report FROM fluxx_workflow_step_run WHERE step_name = 'verify'"));
    }

    #[Test]
    public function a_cancel_marks_the_run_and_its_waiting_steps_cancelled(): void
    {
        $run = $this->storeRun();
        $run->markRunning();
        $waiting = new WorkflowStepRun($run, 'write', 'write_contacts', 1);
        $this->entityManager->persist($waiting);
        $this->entityManager->flush();

        self::assertTrue($this->cancellationService()->cancel('run-1', 'cli', 'Stopped'));

        self::assertSame('cancelled', $this->connection->fetchOne("SELECT status FROM fluxx_workflow_run WHERE run_id = 'run-1'"));
        self::assertSame('cancelled', $this->connection->fetchOne("SELECT status FROM fluxx_workflow_step_run WHERE step_name = 'write_contacts'"));
    }

    #[Test]
    public function a_cancel_leaves_alone_a_run_another_worker_completed_after_it_was_loaded(): void
    {
        $run = $this->storeRun();
        $run->markRunning();
        $this->entityManager->flush();

        $this->connection->executeStatement("UPDATE fluxx_workflow_run SET status = 'completed' WHERE run_id = 'run-1'");

        self::assertFalse($this->cancellationService()->cancel('run-1', 'cli', 'Stopped'));
        self::assertSame('completed', $this->connection->fetchOne("SELECT status FROM fluxx_workflow_run WHERE run_id = 'run-1'"));
    }

    /**
     * @return array{WorkflowRun, WorkflowStepRun}
     */
    private function runWithTwoRunningBranches(): array
    {
        $run = new WorkflowRun(runId: 'run-1', workflowName: 'sync', sourceSystem: 'a', targetSystem: 'b', trigger: 'manual');
        $run->markRunning();
        $contacts = new WorkflowStepRun($run, 'write', 'write_contacts', 1);
        $contacts->markRunning();
        $functions = new WorkflowStepRun($run, 'write', 'write_functions', 2);
        $functions->markRunning();

        $this->entityManager->persist($run);
        $this->entityManager->persist($contacts);
        $this->entityManager->persist($functions);
        $this->entityManager->flush();

        return [$run, $contacts];
    }

    /**
     * @param list<WorkflowStepRun> $stepRuns
     * @return array<string, WorkflowStepRunStatus>
     */
    private function statusesByStep(array $stepRuns): array
    {
        $statuses = [];
        foreach ($stepRuns as $stepRun) {
            $statuses[$stepRun->stepName()] = $stepRun->status();
        }

        return $statuses;
    }

    private function storeRun(): WorkflowRun
    {
        $run = new WorkflowRun(runId: 'run-1', workflowName: 'contacts', sourceSystem: 'CSV', targetSystem: 'Hubspot', trigger: 'manual');
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return $run;
    }

    private function cancellationService(): WorkflowCancellationService
    {
        $registry = $this->registry();
        $workflowRunRepository = new WorkflowRunRepository($registry);
        $workflowStepRunRepository = new WorkflowStepRunRepository($registry);
        $settings = $this->createStub(FluxxSettingLookupInterface::class);
        $settings->method('findValue')->willReturn(null);

        return new WorkflowCancellationService(
            $this->entityManager,
            $workflowRunRepository,
            $workflowStepRunRepository,
            new WorkflowExecutionLockManager(
                $this->entityManager,
                new WorkflowExecutionLockRepository($registry),
                $workflowRunRepository,
                new WorkflowRunAbandonmentDetector($workflowStepRunRepository, $this->createStub(RuntimeWorkerStateLookupInterface::class)),
                new RuntimeSettingsManager($settings, 1800, 120, 60, 300, 10),
            ),
        );
    }

    private function runCheckStep(CheckResult $result): void
    {
        $handler = $this->createStub(CheckStepInterface::class);
        $handler->method('execute')->willReturn(new WorkflowStepResult(checkReport: new CheckReport([$result])));

        $workflow = $this->createStub(WorkflowInterface::class);
        $workflow->method('definition')->willReturn(new WorkflowDefinition(
            code: 'contacts',
            name: 'Contacts',
            sourceSystem: 'CSV',
            targetSystem: 'Hubspot',
            steps: [new WorkflowStepDefinition('verify', 'Verify', WorkflowStepType::Check, $handler)],
        ));

        $registry = $this->registry();
        $workflowRunRepository = new WorkflowRunRepository($registry);
        $workflowStepRunRepository = new WorkflowStepRunRepository($registry);
        $payloadLookup = $this->createStub(WorkflowPayloadLookupInterface::class);
        $payloadStore = $this->createStub(WorkflowPayloadStoreInterface::class);
        $settings = $this->createStub(FluxxSettingLookupInterface::class);
        $settings->method('findValue')->willReturn(null);

        (new FluxxRuntime(
            registry: new SynchronizationRegistry([$workflow]),
            entityManager: $this->entityManager,
            workflowRunRepository: $workflowRunRepository,
            workflowStepRunRepository: $workflowStepRunRepository,
            workflowPayloadRepository: $payloadLookup,
            workflowPayloadStore: $payloadStore,
            workflowContextFactory: new WorkflowContextFactory(),
            workflowExecutionLockManager: $this->createStub(WorkflowExecutionLockManagerInterface::class),
            workflowErrorPayloadFactory: new WorkflowErrorPayloadFactory(),
            workflowRunCompletionDecider: new WorkflowRunCompletionDecider(),
            cancellationSynchronizer: new WorkflowCancellationSynchronizer($workflowRunRepository),
            retryScheduler: new WorkflowRetryScheduler(
                $this->createStub(MessageBusInterface::class),
                new RuntimeSettingsManager($settings, 1800, 120, 60, 300, 10),
            ),
            idempotenceResolver: new WorkflowIdempotenceResolver($workflowStepRunRepository, $payloadLookup, $payloadStore),
        ))->runStep('run-1', 'verify');
    }

    private function openConnection(): Connection
    {
        return DriverManager::getConnection(
            $this->url !== null
                ? (new DsnParser(['postgresql' => 'pdo_pgsql', 'mysql' => 'pdo_mysql']))->parse($this->url)
                : ['driver' => 'pdo_sqlite', 'memory' => true],
        );
    }

    private function registry(): ManagerRegistry
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->entityManager);
        $registry->method('getManager')->willReturn($this->entityManager);

        return $registry;
    }
}
