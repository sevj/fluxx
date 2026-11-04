<?php

declare(strict_types=1);

namespace Fluxx\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Fluxx\Entity\Enum\WorkflowStepType;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Repository\WorkflowRunRepository;
use Fluxx\Repository\WorkflowStepRunRepository;
use Fluxx\StepType\StepTypeRegistry;
use Fluxx\Tests\Fixture\FixtureWorkflow;
use Fluxx\Tests\Fixture\StubExecutableStep;
use Fluxx\Ui\RunDetails;
use Fluxx\Ui\RunDetailView;
use Fluxx\Ui\RunRelaunchView;
use Fluxx\Ui\RunTimeline;
use Fluxx\Workflow\SynchronizationRegistry;
use Fluxx\Workflow\WorkflowDefinition;
use Fluxx\Workflow\WorkflowStepDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Runs against SQLite by default. Set FLUXX_TEST_DATABASE_URL to use another database:
 * its Fluxx tables are dropped and created again, so point it at a throwaway one.
 */
final class RunDetailsDatabaseTest extends TestCase
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
    public function it_links_a_run_to_the_runs_that_relaunched_it_and_nothing_else(): void
    {
        $original = $this->workflowRun('run-1');
        $this->entityManager->persist($original);
        $this->entityManager->persist($this->workflowRun('first-relaunch', 'contacts', ['relaunch' => ['original_run_id' => 'run-1']]));
        $this->entityManager->persist($this->workflowRun('second-relaunch', 'contacts', ['relaunch' => ['original_run_id' => 'run-1']]));
        $this->entityManager->persist($this->workflowRun('longer-id', 'contacts', ['relaunch' => ['original_run_id' => 'run-10']]));
        $this->entityManager->persist($this->workflowRun('in-a-parameter', 'contacts', ['original_run_id' => 'run-1']));
        $this->entityManager->persist($this->workflowRun('other-workflow', 'companies', ['relaunch' => ['original_run_id' => 'run-1']]));
        $this->entityManager->flush();

        self::assertSame(
            ['first-relaunch', 'second-relaunch'],
            array_map(static fn (RunRelaunchView $relaunch): string => $relaunch->runId(), $this->details('run-1')->relaunches()),
        );
        self::assertSame([], $this->details('first-relaunch')->relaunches());
    }

    #[Test]
    public function it_exposes_the_launch_parameters_and_whether_the_run_is_active(): void
    {
        $run = $this->workflowRun('run-1', 'contacts', ['siret' => '123', 'error' => ['message' => 'boom']]);
        $run->markRunning();
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        $details = $this->details('run-1');

        self::assertTrue($details->isActive());
        self::assertSame(['siret' => '123'], json_decode((string) $details->parametersJson(), true));
    }

    private function details(string $runId): RunDetailView
    {
        $definition = new WorkflowDefinition(
            code: 'contacts',
            name: 'Contacts',
            sourceSystem: 'CSV',
            targetSystem: 'Hubspot',
            steps: [
                new WorkflowStepDefinition('read', 'Read', WorkflowStepType::Read, new StubExecutableStep('read', 'Read')),
                new WorkflowStepDefinition('write', 'Write', WorkflowStepType::Write, new StubExecutableStep('write', 'Write'), ['read']),
            ],
        );
        $synchronizationRegistry = new SynchronizationRegistry([new FixtureWorkflow($definition)]);
        $stepTypes = new StepTypeRegistry([]);

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->entityManager);
        $runRepository = new WorkflowRunRepository($registry);

        return (new RunDetails(
            registry: $synchronizationRegistry,
            stepTypeRegistry: $stepTypes,
            workflowRunRepository: $runRepository,
            workflowStepRunRepository: new WorkflowStepRunRepository($registry),
            runTimeline: new RunTimeline($synchronizationRegistry, $stepTypes),
        ))->forWorkflowCodeAndRunId('contacts', $runId);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function workflowRun(string $runId, string $workflowName = 'contacts', array $metadata = []): WorkflowRun
    {
        return new WorkflowRun(runId: $runId, workflowName: $workflowName, sourceSystem: 'CSV', targetSystem: 'Hubspot', trigger: 'manual', metadata: $metadata);
    }
}
