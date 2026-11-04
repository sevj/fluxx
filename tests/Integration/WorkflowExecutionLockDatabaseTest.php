<?php

declare(strict_types=1);

namespace Fluxx\Tests\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Fluxx\Entity\Enum\WorkflowExecutionLockScope;
use Fluxx\Entity\RuntimeWorkerState;
use Fluxx\Entity\WorkflowExecutionLock;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Repository\FluxxSettingLookupInterface;
use Fluxx\Repository\RuntimeWorkerStateRepository;
use Fluxx\Repository\WorkflowExecutionLockRepository;
use Fluxx\Repository\WorkflowRunRepository;
use Fluxx\Repository\WorkflowStepRunRepository;
use Fluxx\Settings\RuntimeSettingsManager;
use Fluxx\Workflow\Lock\WorkflowExecutionLockConfiguration;
use Fluxx\Workflow\Lock\WorkflowExecutionLockConflict;
use Fluxx\Workflow\Lock\WorkflowExecutionLockManager;
use Fluxx\Workflow\Lock\WorkflowRunAbandonmentDetector;
use Fluxx\Workflow\WorkflowDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Runs against SQLite by default. Set FLUXX_TEST_DATABASE_URL to use another database:
 * its Fluxx tables are dropped and created again, so point it at a throwaway one.
 */
final class WorkflowExecutionLockDatabaseTest extends TestCase
{
    private Connection $connection;
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

        $entityManager = $this->entityManager();
        $this->metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $this->schemaTool = new SchemaTool($entityManager);
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
    public function a_live_run_waiting_between_two_steps_keeps_its_lock(): void
    {
        $this->storeOwner(startedAt: new DateTimeImmutable('-1 minute'));

        [, $manager] = $this->services();

        try {
            $manager->acquire($this->workflowRun('second'), $this->definition());
            self::fail('Expected a lock conflict.');
        } catch (WorkflowExecutionLockConflict $conflict) {
            self::assertSame('owner', $conflict->activeRunId());
        }

        self::assertSame('contacts', $this->lockRow('owner')['active_lock_key']);
    }

    #[Test]
    public function an_abandoned_run_gives_up_its_lock_to_a_new_run(): void
    {
        $this->storeOwner(startedAt: new DateTimeImmutable('-1 hour'));

        [$entityManager, $manager] = $this->services();
        $run = $this->workflowRun('second');
        $manager->acquire($run, $this->definition());
        $entityManager->persist($run);
        $entityManager->flush();

        self::assertNull($this->lockRow('owner')['active_lock_key']);
        self::assertSame('stale_recovered', $this->lockRow('owner')['release_reason']);
        self::assertSame('contacts', $this->lockRow('second')['active_lock_key']);
    }

    #[Test]
    public function a_worker_processing_the_run_protects_its_lock_once_it_is_inactive(): void
    {
        $this->storeOwner(startedAt: new DateTimeImmutable('-1 hour'));

        $worker = new RuntimeWorkerState('worker-1', 'fluxx', 'host', 1);
        $worker->markProcessing('message', 'id', 'contacts', 'owner', 'step');
        [$entityManager, $manager] = $this->services();
        $entityManager->persist($worker);
        $entityManager->flush();

        $this->expectException(WorkflowExecutionLockConflict::class);

        $manager->acquire($this->workflowRun('second'), $this->definition());
    }

    #[Test]
    public function simultaneous_launches_end_in_a_lock_conflict(): void
    {
        [$firstEntityManager, $firstManager] = $this->services();
        [$secondEntityManager, $secondManager] = $this->services();

        $first = $this->workflowRun('first');
        $second = $this->workflowRun('second');

        // Both pass the check before either of them has written its lock.
        $firstManager->acquire($first, $this->definition());
        $firstEntityManager->persist($first);
        $secondManager->acquire($second, $this->definition());
        $secondEntityManager->persist($second);

        $firstEntityManager->flush();

        try {
            $secondEntityManager->flush();
            self::fail('The database accepted a second active lock.');
        } catch (UniqueConstraintViolationException $exception) {
            $failure = $secondManager->flushFailure($second, $exception);
        }

        self::assertInstanceOf(WorkflowExecutionLockConflict::class, $failure);
        self::assertSame('first', $failure->activeRunId());
        self::assertSame('contacts', $failure->lockKey());
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM fluxx_workflow_run WHERE run_id = 'second'"));
    }

    #[Test]
    public function a_key_can_be_locked_again_once_released_and_keeps_its_history(): void
    {
        [$entityManager] = $this->services();

        for ($iteration = 1; $iteration <= 3; ++$iteration) {
            $lock = new WorkflowExecutionLock('contacts', 'run-' . $iteration, 'contacts', WorkflowExecutionLockScope::Workflow);
            $entityManager->persist($lock);
            $entityManager->flush();
            $lock->release('completed');
            $entityManager->flush();
        }

        self::assertSame(3, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM fluxx_workflow_execution_lock'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(active_lock_key) FROM fluxx_workflow_execution_lock'));
    }

    /**
     * @return array<string, mixed>
     */
    private function lockRow(string $ownerRunId): array
    {
        return (array) $this->connection->fetchAssociative(
            'SELECT active_lock_key, release_reason FROM fluxx_workflow_execution_lock WHERE owner_run_id = ?',
            [$ownerRunId],
        );
    }

    private function storeOwner(DateTimeImmutable $startedAt): void
    {
        [$entityManager] = $this->services();

        $owner = $this->workflowRun('owner');
        $owner->markRunning($startedAt);
        $owner->attachExecutionLock('contacts', WorkflowExecutionLockScope::Workflow);
        $entityManager->persist($owner);
        $entityManager->persist(new WorkflowExecutionLock('contacts', 'owner', 'contacts', WorkflowExecutionLockScope::Workflow));
        $entityManager->flush();
        $entityManager->clear();
    }

    private function workflowRun(string $runId): WorkflowRun
    {
        return new WorkflowRun(runId: $runId, workflowName: 'contacts', sourceSystem: 'CSV', targetSystem: 'Hubspot', trigger: 'manual');
    }

    private function definition(): WorkflowDefinition
    {
        return new WorkflowDefinition(
            code: 'contacts',
            name: 'Contacts',
            sourceSystem: 'CSV',
            targetSystem: 'Hubspot',
            steps: [],
            lock: new WorkflowExecutionLockConfiguration(WorkflowExecutionLockScope::Workflow, staleTimeoutSeconds: 300),
        );
    }

    /**
     * @return array{EntityManager, WorkflowExecutionLockManager}
     */
    private function services(): array
    {
        $entityManager = $this->entityManager();

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($entityManager);

        $settings = $this->createStub(FluxxSettingLookupInterface::class);
        $settings->method('findValue')->willReturn(null);

        return [$entityManager, new WorkflowExecutionLockManager(
            entityManager: $entityManager,
            workflowExecutionLockRepository: new WorkflowExecutionLockRepository($registry),
            workflowRunRepository: new WorkflowRunRepository($registry),
            abandonmentDetector: new WorkflowRunAbandonmentDetector(
                new WorkflowStepRunRepository($registry),
                new RuntimeWorkerStateRepository($registry),
            ),
            runtimeSettingsManager: new RuntimeSettingsManager($settings, 1800, 120, 60, 300, 10),
        )];
    }

    private function entityManager(): EntityManager
    {
        $configuration = ORMSetup::createAttributeMetadataConfig([__DIR__ . '/../../src/Entity'], true);
        $configuration->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER, true));
        $configuration->enableNativeLazyObjects(true);

        return new EntityManager($this->connection, $configuration);
    }
}
