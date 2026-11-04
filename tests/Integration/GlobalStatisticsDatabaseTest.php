<?php

declare(strict_types=1);

namespace Fluxx\Tests\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;
use Fluxx\Reporting\WorkflowStepRunStatistics;
use Fluxx\Repository\WorkflowRunRepository;
use Fluxx\Tests\Fixture\FixtureWorkflow;
use Fluxx\Ui\GlobalStatistics;
use Fluxx\Ui\WorkflowStatisticsMetricView;
use Fluxx\Ui\WorkflowStatisticsView;
use Fluxx\Ui\WorkflowStatisticsViewFactory;
use Fluxx\Workflow\SynchronizationRegistry;
use Fluxx\Workflow\WorkflowDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Runs against SQLite by default. Set FLUXX_TEST_DATABASE_URL to use another database:
 * its Fluxx tables are dropped and created again, so point it at a throwaway one.
 */
final class GlobalStatisticsDatabaseTest extends TestCase
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

        $this->storeRun('contacts-ok', 'contacts', 'completed', processed: 10);
        $this->storeRun('contacts-ko', 'contacts', 'failed', processed: 5);
        $this->storeRun('orders-ok', 'orders', 'completed', processed: 100);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        if (isset($this->schemaTool)) {
            $this->schemaTool->dropSchema($this->metadata);
            $this->connection->close();
        }
    }

    #[Test]
    public function without_a_filter_every_workflow_counts(): void
    {
        $statistics = $this->statistics()->build('week');

        self::assertNull($statistics->workflowCode());
        self::assertSame(3, $statistics->executionTotal());
        self::assertSame(1, $statistics->errorTotal());
        self::assertSame('115', $this->metric($statistics, 'statistics_chart.metric_processed_total'));
    }

    #[Test]
    public function a_filter_restricts_the_totals_the_chart_and_the_metrics_to_that_workflow(): void
    {
        $statistics = $this->statistics()->build('week', 'contacts');

        self::assertSame('contacts', $statistics->workflowCode());
        self::assertSame(2, $statistics->executionTotal());
        self::assertSame(1, $statistics->errorTotal());
        self::assertSame(2, array_sum(array_map(static fn ($point): int => $point->executionCount(), $statistics->points())));
        self::assertSame('15', $this->metric($statistics, 'statistics_chart.metric_processed_total'));
        self::assertSame('50.0%', $this->metric($statistics, 'statistics_chart.metric_failure_rate'));
    }

    #[Test]
    public function a_workflow_without_any_run_gives_an_empty_page_that_keeps_the_filter(): void
    {
        $statistics = $this->statistics()->build('week', 'invoices');

        self::assertSame('invoices', $statistics->workflowCode());
        self::assertTrue($statistics->isEmpty());
    }

    #[Test]
    public function durations_ignore_failed_runs_because_they_end_when_they_get_cleaned_up(): void
    {
        $run = new WorkflowRun(runId: 'cleaned-up', workflowName: 'contacts', sourceSystem: 'CSV', targetSystem: 'Hubspot', trigger: 'manual');
        $run->markRunning(new DateTimeImmutable('-3 days'));
        $run->markFailed('no worker activity');
        $this->entityManager->persist($run);

        $finished = new WorkflowRun(runId: 'finished', workflowName: 'contacts', sourceSystem: 'CSV', targetSystem: 'Hubspot', trigger: 'manual');
        $finished->markRunning(new DateTimeImmutable('2026-01-01 10:00:00'));
        $finished->markCompleted(new DateTimeImmutable('2026-01-01 10:00:20'));
        $this->entityManager->persist($finished);
        $this->entityManager->flush();

        $this->connection->executeStatement("DELETE FROM fluxx_workflow_step_run");
        $this->connection->executeStatement("DELETE FROM fluxx_workflow_run WHERE run_id IN ('contacts-ok', 'contacts-ko', 'orders-ok')");

        self::assertSame('20.0 s', $this->metric($this->statistics()->build('week', 'contacts'), 'statistics_chart.metric_avg_duration'));
    }

    #[Test]
    public function a_failed_step_is_not_counted_as_a_rejected_record(): void
    {
        $run = new WorkflowRun(runId: 'rejects', workflowName: 'orders', sourceSystem: 'CSV', targetSystem: 'Hubspot', trigger: 'manual');
        $run->markRunning();
        $run->markFailed('boom');

        $rejecting = new WorkflowStepRun($run, 'write', 'write', 1);
        $rejecting->markRunning();
        $rejecting->markCompleted(processedCount: 8, successCount: 5, errorCount: 3);

        $failing = new WorkflowStepRun($run, 'send', 'send', 2);
        $failing->markRunning();
        $failing->markFailed('boom', errorCount: 1);

        $this->entityManager->persist($run);
        $this->entityManager->persist($rejecting);
        $this->entityManager->persist($failing);
        $this->entityManager->flush();

        self::assertSame('3', $this->metric($this->statistics()->build('week', 'orders'), 'statistics_chart.metric_record_errors_total'));
    }

    #[Test]
    public function an_unknown_workflow_is_ignored_rather_than_showing_an_empty_page(): void
    {
        $statistics = $this->statistics()->build('week', 'does-not-exist');

        self::assertNull($statistics->workflowCode());
        self::assertSame(3, $statistics->executionTotal());
    }

    private function metric(WorkflowStatisticsView $statistics, string $labelKey): string
    {
        foreach ($statistics->metrics() as $metric) {
            if ($metric instanceof WorkflowStatisticsMetricView && $metric->labelKey() === $labelKey) {
                return $metric->value();
            }
        }

        self::fail('Missing metric ' . $labelKey);
    }

    private function storeRun(string $runId, string $workflowCode, string $status, int $processed): void
    {
        $run = new WorkflowRun(runId: $runId, workflowName: $workflowCode, sourceSystem: 'CSV', targetSystem: 'Hubspot', trigger: 'manual');
        $run->markRunning();
        $status === 'failed' ? $run->markFailed('boom') : $run->markCompleted();

        $step = new WorkflowStepRun($run, 'read', 'read', 1);
        $step->markRunning();
        $step->markCompleted(processedCount: $processed, successCount: $processed);

        $this->entityManager->persist($run);
        $this->entityManager->persist($step);
    }

    private function statistics(): GlobalStatistics
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->entityManager);
        $registry->method('getManager')->willReturn($this->entityManager);

        return new GlobalStatistics(
            new WorkflowStatisticsViewFactory(new WorkflowRunRepository($registry), new WorkflowStepRunStatistics($registry)),
            new SynchronizationRegistry(array_map(
                static fn (string $code): FixtureWorkflow => new FixtureWorkflow(new WorkflowDefinition(
                    code: $code,
                    name: ucfirst($code),
                    sourceSystem: 'CSV',
                    targetSystem: 'Hubspot',
                    steps: [],
                )),
                ['contacts', 'orders', 'invoices'],
            )),
        );
    }
}
