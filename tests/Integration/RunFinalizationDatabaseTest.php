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
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;
use Fluxx\Repository\WorkflowStepRunRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
