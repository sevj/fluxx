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
use Fluxx\Entity\Enum\WorkflowStepType;
use Fluxx\Entity\RuntimeWorkerState;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Repository\FluxxSettingLookupInterface;
use Fluxx\Repository\WorkflowPayloadLookupInterface;
use Fluxx\Repository\WorkflowRunRepository;
use Fluxx\Repository\WorkflowStepRunRepository;
use Fluxx\Settings\RuntimeSettingsManager;
use Fluxx\Workflow\Context\WorkflowContextFactory;
use Fluxx\Workflow\Error\WorkflowErrorPayloadFactory;
use Fluxx\Workflow\Lock\WorkflowExecutionLockManagerInterface;
use Fluxx\Workflow\Payload\WorkflowPayloadStoreInterface;
use Fluxx\Workflow\Result\WorkflowStepResult;
use Fluxx\Workflow\Runtime\FluxxRuntime;
use Fluxx\Workflow\Runtime\WorkflowCancellationSynchronizer;
use Fluxx\Workflow\Runtime\WorkflowIdempotenceResolver;
use Fluxx\Workflow\Runtime\WorkflowRetryScheduler;
use Fluxx\Workflow\Runtime\WorkflowRunCompletionDecider;
use Fluxx\Workflow\Runtime\WorkflowStepFailedException;
use Fluxx\Workflow\Step\ExecutableWorkflowStepInterface;
use Fluxx\Workflow\SynchronizationRegistry;
use Fluxx\Workflow\WorkflowDefinition;
use Fluxx\Workflow\WorkflowInterface;
use Fluxx\Workflow\WorkflowStepDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Runs against SQLite by default. Set FLUXX_TEST_DATABASE_URL to use another database:
 * its Fluxx tables are dropped and created again, so point it at a throwaway one.
 */
final class StepTransactionDatabaseTest extends TestCase
{
    private Connection $connection;
    private EntityManager $entityManager;
    private SchemaTool $schemaTool;

    /** @var list<\Doctrine\ORM\Mapping\ClassMetadata<object>> */
    private array $metadata;

    protected function setUp(): void
    {
        $url = getenv('FLUXX_TEST_DATABASE_URL') ?: null;

        if ($url === null && !extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('Requires pdo_sqlite or FLUXX_TEST_DATABASE_URL.');
        }

        $this->connection = DriverManager::getConnection(
            $url !== null
                ? (new DsnParser(['postgresql' => 'pdo_pgsql', 'mysql' => 'pdo_mysql']))->parse($url)
                : ['driver' => 'pdo_sqlite', 'memory' => true],
        );

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
    public function what_a_handler_wrote_stays_when_it_fails_afterwards(): void
    {
        $this->storeRun();

        try {
            $this->runStep(function (): void {
                $this->entityManager->persist(new RuntimeWorkerState('written-by-the-step', 'fluxx', 'host', 1));
                $this->entityManager->flush();

                throw new RuntimeException('boom');
            });
            self::fail('Expected the step to fail.');
        } catch (WorkflowStepFailedException) {
        }

        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM fluxx_runtime_worker_state WHERE worker_name = 'written-by-the-step'"));
        self::assertSame('failed', $this->stepStatus());
    }

    #[Test]
    public function other_connections_see_what_the_handler_wrote_while_the_step_runs(): void
    {
        $this->requirePostgreSql();
        $this->storeRun();

        $seenFromOutside = null;
        $this->runStep(function () use (&$seenFromOutside): void {
            $this->entityManager->persist(new RuntimeWorkerState('written-by-the-step', 'fluxx', 'host', 1));
            $this->entityManager->flush();

            $seenFromOutside = (int) $this->otherConnection()->fetchOne("SELECT COUNT(*) FROM fluxx_runtime_worker_state WHERE worker_name = 'written-by-the-step'");
        });

        self::assertSame(1, $seenFromOutside);
        self::assertSame('completed', $this->stepStatus());
    }

    #[Test]
    public function a_step_completes_when_the_connection_is_lost_during_its_handler(): void
    {
        $this->requirePostgreSql();
        $this->storeRun();

        $this->runStep(fn () => $this->killConnection());

        self::assertSame('completed', $this->stepStatus());
        self::assertSame('completed', $this->connection->fetchOne("SELECT status FROM fluxx_workflow_run WHERE run_id = 'run-1'"));
    }

    #[Test]
    public function a_step_failure_is_recorded_when_the_connection_is_lost_during_its_handler(): void
    {
        $this->requirePostgreSql();
        $this->storeRun();

        try {
            $this->runStep(function (): void {
                $this->killConnection();

                throw new RuntimeException('boom');
            });
            self::fail('Expected the step to fail.');
        } catch (WorkflowStepFailedException) {
        }

        self::assertSame('failed', $this->stepStatus());
    }

    private function requirePostgreSql(): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            self::markTestSkipped('Requires FLUXX_TEST_DATABASE_URL to point at PostgreSQL.');
        }
    }

    private function otherConnection(): Connection
    {
        return DriverManager::getConnection($this->connection->getParams());
    }

    private function killConnection(): void
    {
        $pid = $this->connection->fetchOne('SELECT pg_backend_pid()');
        $this->otherConnection()->executeQuery('SELECT pg_terminate_backend(?)', [$pid]);
        usleep(100_000);
    }

    private function storeRun(): void
    {
        $this->entityManager->persist(new WorkflowRun(runId: 'run-1', workflowName: 'contacts', sourceSystem: 'CSV', targetSystem: 'Hubspot', trigger: 'manual'));
        $this->entityManager->flush();
    }

    private function stepStatus(): string
    {
        return (string) $this->connection->fetchOne("SELECT status FROM fluxx_workflow_step_run WHERE step_name = 'fetch'");
    }

    private function runStep(\Closure $work): void
    {
        $handler = $this->createStub(ExecutableWorkflowStepInterface::class);
        $handler->method('execute')->willReturnCallback(static function () use ($work): WorkflowStepResult {
            $work();

            return new WorkflowStepResult(records: []);
        });

        $workflow = $this->createStub(WorkflowInterface::class);
        $workflow->method('definition')->willReturn(new WorkflowDefinition(
            code: 'contacts',
            name: 'Contacts',
            sourceSystem: 'CSV',
            targetSystem: 'Hubspot',
            steps: [new WorkflowStepDefinition('fetch', 'Fetch', WorkflowStepType::Read, $handler)],
        ));

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->entityManager);
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
        ))->runStep('run-1', 'fetch');
    }
}
