<?php

declare(strict_types=1);

namespace Fluxx\Tests\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Fluxx\Command\BuildRunReportCommand;
use Fluxx\Entity\Enum\WorkflowRunStatus;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;
use Fluxx\Repository\WorkflowRunReportRepository;
use Fluxx\Repository\WorkflowRunRepository;
use Fluxx\Repository\WorkflowStepRunRepository;
use Fluxx\Tests\Fixture\FixtureWorkflow;
use Fluxx\Tests\Fixture\StubExecutableStep;
use Fluxx\Workflow\Check\CheckOutcome;
use Fluxx\Workflow\Check\CheckReport;
use Fluxx\Workflow\Check\CheckResult;
use Fluxx\Workflow\Message\BuildRunReportMessage;
use Fluxx\Workflow\MessageHandler\BuildRunReportHandler;
use Fluxx\Workflow\Report\ReportSection;
use Fluxx\Workflow\Report\RunReportBuilder;
use Fluxx\Workflow\Report\RunReportSectionInterface;
use Fluxx\Workflow\Report\Severity;
use Fluxx\Workflow\Report\StepReport;
use Fluxx\Workflow\Report\WorkflowReportConfiguration;
use Fluxx\Workflow\SynchronizationRegistry;
use Fluxx\Workflow\WorkflowDefinition;
use Fluxx\Workflow\WorkflowStepDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * Runs against SQLite by default. Set FLUXX_TEST_DATABASE_URL to use another database:
 * its Fluxx tables are dropped and created again, so point it at a throwaway one.
 */
final class RunReportDatabaseTest extends TestCase
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
    public function no_report_without_configuration(): void
    {
        $this->storeCompletedRun('run-1');

        $this->handler(report: null)(new BuildRunReportMessage('run-1'));

        self::assertSame(0, $this->reportCount());
    }

    #[Test]
    public function failing_section_is_marked_unavailable_and_report_saved(): void
    {
        $this->storeCompletedRun('run-1');

        $this->handler(new WorkflowReportConfiguration([ThrowingSection::class]))(new BuildRunReportMessage('run-1'));

        $content = $this->storedContent('run-1');
        self::assertSame(
            [['title' => 'ThrowingSection', 'available' => false, 'message' => 'Source unreachable', 'lines' => []]],
            $content['sections'],
        );
        self::assertSame('completed_with_warnings', $content['summary']['status']);
        self::assertSame(
            WorkflowRunStatus::CompletedWithWarnings->value,
            $this->connection->fetchOne("SELECT status FROM fluxx_workflow_run WHERE run_id = 'run-1'"),
        );
    }

    #[Test]
    public function a_failed_run_gets_no_report_from_the_handler(): void
    {
        $run = new WorkflowRun('run-1', 'sync', 'src', 'tgt', 'manual');
        $run->markRunning();
        $run->markFailed('boom');
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        $this->handler(new WorkflowReportConfiguration())(new BuildRunReportMessage('run-1'));

        self::assertSame(0, $this->reportCount());
    }

    #[Test]
    public function rebuild_after_payload_prune(): void
    {
        $this->storeCompletedRun('run-1');
        $this->runRepository()->markRunsAsPayloadsPruned(new DateTimeImmutable('+1 minute'), [WorkflowRunStatus::CompletedWithWarnings->value]);
        $this->entityManager->clear();

        $tester = new CommandTester($this->command());

        self::assertSame(Command::SUCCESS, $tester->execute(['run-id' => 'run-1']));
        $content = $this->storedContent('run-1');
        self::assertSame('completed_with_warnings', $content['summary']['status']);
        self::assertSame(1, $content['summary']['warning_count']);
        self::assertSame(
            [['label' => 'Contacts', 'read' => 10, 'created' => 4, 'updated' => 3, 'written' => 7, 'excluded' => ['No email' => 2], 'excluded_total' => 2, 'rejected' => [], 'rejected_total' => 0, 'steps' => ['write']]],
            $content['objects'],
        );
        self::assertSame(['Main role set'], array_column($content['checks'], 'name'));
        self::assertSame(['read', 'write'], $content['critical_path']);
    }

    #[Test]
    public function rebuild_replaces_existing_report(): void
    {
        $this->storeCompletedRun('run-1');
        $handler = $this->handler(new WorkflowReportConfiguration());
        $handler(new BuildRunReportMessage('run-1'));
        $this->connection->executeStatement("UPDATE fluxx_workflow_run_report SET content = '{}', built_at = '2020-01-01 00:00:00'");
        $this->entityManager->clear();

        self::assertSame(Command::SUCCESS, (new CommandTester($this->command()))->execute(['run-id' => 'run-1']));

        self::assertSame(1, $this->reportCount());
        self::assertSame('run-1', $this->storedContent('run-1')['summary']['run_id']);
        self::assertNotSame('2020-01-01 00:00:00', $this->connection->fetchOne('SELECT built_at FROM fluxx_workflow_run_report'));
    }

    #[Test]
    public function the_command_refuses_a_run_that_did_not_complete(): void
    {
        $run = new WorkflowRun('run-1', 'sync', 'src', 'tgt', 'manual');
        $run->markRunning();
        $step = new WorkflowStepRun($run, 'read', 'read', 1);
        $step->markRunning();
        $step->markFailed('boom');
        $run->markFailed('boom');
        $this->entityManager->persist($run);
        $this->entityManager->flush();
        $this->runRepository()->markRunsAsPayloadsPruned(new DateTimeImmutable('+1 minute'), [WorkflowRunStatus::Failed->value]);
        $this->entityManager->clear();

        $tester = new CommandTester($this->command());

        self::assertSame(Command::FAILURE, $tester->execute(['run-id' => 'run-1']));
        self::assertStringContainsString('did not complete', $tester->getDisplay());
        self::assertSame(0, $this->reportCount());
    }

    #[Test]
    public function the_report_is_deleted_with_its_run(): void
    {
        $this->storeCompletedRun('run-1');
        $this->handler(new WorkflowReportConfiguration())(new BuildRunReportMessage('run-1'));

        if ($this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->connection->executeStatement('PRAGMA foreign_keys = ON');
        }

        $this->connection->executeStatement("DELETE FROM fluxx_workflow_run WHERE run_id = 'run-1'");

        self::assertSame(0, $this->reportCount());
    }

    private function storeCompletedRun(string $runId): void
    {
        $origin = new DateTimeImmutable('2026-11-17 08:00:00');
        $run = new WorkflowRun($runId, 'sync', 'src', 'tgt', 'manual');
        $run->markRunning($origin);

        $read = new WorkflowStepRun($run, 'read', 'read', 1);
        $read->markRunning($origin);
        $read->markCompleted(10, 10, durationMs: 10000, finishedAt: $origin->modify('+10 seconds'));

        $write = new WorkflowStepRun($run, 'write', 'write', 2);
        $write->markRunning($origin->modify('+10 seconds'));
        $write->replaceReport(StepReport::objects('Contacts')->read(10)->created(4)->updated(3)->excluded('No email', 2)->toArray());
        $write->markCompleted(10, 10, durationMs: 50000, finishedAt: $origin->modify('+60 seconds'));

        $verify = new WorkflowStepRun($run, 'check', 'verify', 3);
        $verify->markRunning($origin->modify('+10 seconds'));
        $verify->replaceReport((new CheckReport())->add(new CheckResult('Main role set', Severity::Warning, CheckOutcome::Deviation, '4', '3'))->toArray());
        $verify->markCompletedWithWarnings(1, 1, durationMs: 5000, finishedAt: $origin->modify('+15 seconds'));

        $run->incrementWarningCount();
        $run->markCompletedWithWarnings($origin->modify('+61 seconds'));

        $this->entityManager->persist($run);
        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    private function handler(?WorkflowReportConfiguration $report): BuildRunReportHandler
    {
        return new BuildRunReportHandler($this->runRepository(), $this->registry($report), $this->builder($report), $this->reportRepository());
    }

    private function command(): BuildRunReportCommand
    {
        return new BuildRunReportCommand($this->runRepository(), $this->builder(new WorkflowReportConfiguration()), $this->reportRepository());
    }

    private function builder(?WorkflowReportConfiguration $report): RunReportBuilder
    {
        return new RunReportBuilder(
            $this->registry($report),
            new WorkflowStepRunRepository($this->managerRegistry()),
            new ServiceLocator([ThrowingSection::class => static fn (): ThrowingSection => new ThrowingSection()]),
        );
    }

    private function registry(?WorkflowReportConfiguration $report): SynchronizationRegistry
    {
        $step = static fn (string $code, array $dependsOn = []): WorkflowStepDefinition => new WorkflowStepDefinition($code, ucfirst($code), 'transform', new StubExecutableStep($code, $code), $dependsOn);

        return new SynchronizationRegistry([new FixtureWorkflow(new WorkflowDefinition(
            'sync',
            'Sync',
            'src',
            'tgt',
            [$step('read'), $step('write', ['read']), $step('verify', ['read'])],
            report: $report,
        ))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function storedContent(string $runId): array
    {
        $json = $this->connection->fetchOne(
            'SELECT report.content FROM fluxx_workflow_run_report report JOIN fluxx_workflow_run run ON run.id = report.workflow_run_id WHERE run.run_id = ?',
            [$runId],
        );

        return json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);
    }

    private function reportCount(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM fluxx_workflow_run_report');
    }

    private function managerRegistry(): ManagerRegistry
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->entityManager);
        $registry->method('getManager')->willReturn($this->entityManager);

        return $registry;
    }

    private function runRepository(): WorkflowRunRepository
    {
        return new WorkflowRunRepository($this->managerRegistry());
    }

    private function reportRepository(): WorkflowRunReportRepository
    {
        return new WorkflowRunReportRepository($this->managerRegistry());
    }
}

final class ThrowingSection implements RunReportSectionInterface
{
    public function build(WorkflowRun $run): ReportSection
    {
        throw new \RuntimeException('Source unreachable');
    }
}
