<?php

declare(strict_types=1);

namespace Fluxx\Tests\Integration;

use Fluxx\Workflow\Check\CheckOutcome;
use Fluxx\Workflow\Check\CheckReport;
use Fluxx\Workflow\Check\CheckResult;
use Fluxx\Workflow\Report\Severity;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Fluxx\Entity\Enum\WorkflowRunStatus;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;
use Fluxx\Reporting\DailyWorkflowRecapBuilder;
use Fluxx\Reporting\WorkflowStepRunStatistics;
use Fluxx\Repository\WorkflowRunRepository;
use Fluxx\Ui\WorkflowRunFilters;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Runs against SQLite by default. Set FLUXX_TEST_DATABASE_URL to use another database:
 * its Fluxx tables are dropped and created again, so point it at a throwaway one.
 */
final class RunWarningsDatabaseTest extends TestCase
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
    public function prune_keeps_warning_count(): void
    {
        $this->storeWarningRun('warned');
        $this->entityManager->flush();

        $marked = $this->runRepository()->markRunsAsPayloadsPruned(
            new DateTimeImmutable('+1 minute'),
            [WorkflowRunStatus::CompletedWithWarnings->value],
        );

        self::assertSame(1, $marked);
        self::assertSame(
            ['status' => WorkflowRunStatus::PayloadsPruned->value, 'warning_count' => 2],
            $this->connection->fetchAssociative("SELECT status, warning_count FROM fluxx_workflow_run WHERE run_id = 'warned'"),
        );
    }

    #[Test]
    public function recap_lists_runs_with_warnings(): void
    {
        $this->storeWarningRun('warned');
        $clean = new WorkflowRun(runId: 'clean', workflowName: 'contacts', sourceSystem: 'CSV', targetSystem: 'Hubspot', trigger: 'manual');
        $clean->markRunning();
        $clean->markCompleted();
        $this->entityManager->persist($clean);
        $this->entityManager->flush();

        $recap = $this->recapBuilder()->build(new DateTimeImmutable('-1 hour'), new DateTimeImmutable('+1 hour'));

        self::assertSame(1, $recap->statusCounts()[WorkflowRunStatus::CompletedWithWarnings->value] ?? null);
        self::assertSame([], $recap->erroredRuns());
        self::assertCount(1, $recap->warningRuns());
        self::assertSame('warned', $recap->warningRuns()[0]['runId']);
        self::assertSame(2, $recap->warningRuns()[0]['warningCount']);
        self::assertSame(['check'], array_column($recap->warningRuns()[0]['steps'], 'code'));
        self::assertSame(
            [['name' => 'Main role set', 'severity' => 'warning', 'expected' => '4', 'actual' => '3']],
            $recap->warningRuns()[0]['steps'][0]['checkDeviations'],
        );
    }

    #[Test]
    public function recap_still_lists_a_pruned_run_with_warnings(): void
    {
        $this->storeWarningRun('warned');
        $this->entityManager->flush();
        $this->runRepository()->markRunsAsPayloadsPruned(new DateTimeImmutable('+1 minute'), [WorkflowRunStatus::CompletedWithWarnings->value]);
        $this->entityManager->clear();

        $recap = $this->recapBuilder()->build(new DateTimeImmutable('-1 hour'), new DateTimeImmutable('+1 hour'));

        self::assertSame(['warned'], array_column($recap->warningRuns(), 'runId'));
    }

    #[Test]
    public function the_warning_filter_keeps_a_pruned_run_with_warnings(): void
    {
        $this->storeWarningRun('warned');
        $this->storeWarningRun('pruned');
        $clean = new WorkflowRun(runId: 'clean', workflowName: 'contacts', sourceSystem: 'CSV', targetSystem: 'Hubspot', trigger: 'manual');
        $clean->markRunning();
        $clean->markCompleted();
        $this->entityManager->persist($clean);
        $this->entityManager->flush();
        $this->connection->executeStatement("UPDATE fluxx_workflow_run SET status = 'payloads_pruned' WHERE run_id IN ('pruned', 'clean')");
        $this->entityManager->clear();

        $runs = $this->runRepository()->findPaginatedByFilters(new WorkflowRunFilters(status: WorkflowRunStatus::CompletedWithWarnings->value), 10, 0);

        self::assertEqualsCanonicalizing(['warned', 'pruned'], array_map(static fn (WorkflowRun $run): string => $run->runId(), $runs));
    }

    #[Test]
    public function a_run_completed_with_warnings_stays_listed_after_prune_without_explicit_count(): void
    {
        $run = new WorkflowRun(runId: 'resumed', workflowName: 'contacts', sourceSystem: 'CSV', targetSystem: 'Hubspot', trigger: 'relaunch');
        $run->markRunning();
        $run->markCompletedWithWarnings();
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        $this->runRepository()->markRunsAsPayloadsPruned(new DateTimeImmutable('+1 minute'), [WorkflowRunStatus::CompletedWithWarnings->value]);
        $this->entityManager->clear();

        $runs = $this->runRepository()->findPaginatedByFilters(new WorkflowRunFilters(status: WorkflowRunStatus::CompletedWithWarnings->value), 10, 0);

        self::assertSame(['resumed'], array_map(static fn (WorkflowRun $run): string => $run->runId(), $runs));
        self::assertSame(WorkflowRunStatus::PayloadsPruned, $runs[0]->status());
        self::assertSame(1, $runs[0]->warningCount());
    }

    private function storeWarningRun(string $runId): void
    {
        $run = new WorkflowRun(runId: $runId, workflowName: 'contacts', sourceSystem: 'CSV', targetSystem: 'Hubspot', trigger: 'manual');
        $run->markRunning();

        $read = new WorkflowStepRun($run, 'read', 'read', 1);
        $read->markRunning();
        $read->markCompleted(processedCount: 3, successCount: 3);

        $check = new WorkflowStepRun($run, 'check', 'check', 2);
        $check->markRunning();
        $check->replaceReport((new CheckReport())
            ->add(new CheckResult('Main role set', Severity::Warning, CheckOutcome::Deviation, '4', '3', ['c-9']))
            ->add(new CheckResult('Companies present', Severity::Blocking, CheckOutcome::Ok, '12', '12'))
            ->toArray());
        $check->markCompletedWithWarnings(processedCount: 3, successCount: 3);

        $run->incrementWarningCount(2);
        $run->markCompletedWithWarnings();

        $this->entityManager->persist($run);
        $this->entityManager->persist($read);
        $this->entityManager->persist($check);
    }

    private function registry(): ManagerRegistry
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->entityManager);
        $registry->method('getManager')->willReturn($this->entityManager);

        return $registry;
    }

    private function runRepository(): WorkflowRunRepository
    {
        return new WorkflowRunRepository($this->registry());
    }

    private function recapBuilder(): DailyWorkflowRecapBuilder
    {
        return new DailyWorkflowRecapBuilder($this->runRepository(), new WorkflowStepRunStatistics($this->registry()));
    }
}
