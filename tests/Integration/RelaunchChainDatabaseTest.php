<?php

declare(strict_types=1);

namespace Fluxx\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Repository\RepositoryFactory;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Fluxx\Entity\Enum\WorkflowStepType;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;
use Fluxx\Repository\WorkflowPayloadRepository;
use Fluxx\Repository\WorkflowRunRepository;
use Fluxx\Repository\WorkflowStepRunRepository;
use Fluxx\Workflow\Lock\WorkflowExecutionLockManagerInterface;
use Fluxx\Workflow\Payload\WorkflowPayloadStore;
use Fluxx\Workflow\Relaunch\WorkflowRelaunchMode;
use Fluxx\Workflow\Relaunch\WorkflowRelaunchPlanner;
use Fluxx\Workflow\Relaunch\WorkflowRelaunchService;
use Fluxx\Workflow\Step\JoinStep;
use Fluxx\Workflow\SynchronizationRegistry;
use Fluxx\Workflow\WorkflowDefinition;
use Fluxx\Workflow\WorkflowInterface;
use Fluxx\Workflow\WorkflowStepDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Runs against SQLite by default. Set FLUXX_TEST_DATABASE_URL to use another database:
 * its Fluxx tables are dropped and created again, so point it at a throwaway one.
 */
final class RelaunchChainDatabaseTest extends TestCase
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
        $configuration->setRepositoryFactory(new class ($this->registry(...)) implements RepositoryFactory {
            /** @var array<string, EntityRepository<object>> */
            private array $repositories = [];

            public function __construct(private readonly \Closure $registry)
            {
            }

            public function getRepository(EntityManagerInterface $entityManager, string $entityName): EntityRepository
            {
                $class = $entityManager->getClassMetadata($entityName)->customRepositoryClassName ?? EntityRepository::class;

                return $this->repositories[$entityName] ??= $class === EntityRepository::class
                    ? new EntityRepository($entityManager, $entityManager->getClassMetadata($entityName))
                    : new $class(($this->registry)());
            }
        });
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
    public function a_relaunch_of_a_relaunch_finds_the_outputs_its_parent_did_not_need(): void
    {
        $store = new WorkflowPayloadStore($this->entityManager);
        $first = new WorkflowRun('run-1', 'fixture', 'a', 'b', 'manual');
        $first->markRunning();
        $this->entityManager->persist($first);
        $steps = [];
        foreach (['fetch', 'transform', 'write', 'verify'] as $position => $code) {
            $steps[$code] = new WorkflowStepRun($first, 'read', $code, $position + 1);
            $steps[$code]->markRunning();
            $steps[$code]->markCompleted(processedCount: 1, successCount: 1, errorCount: 0);
            $this->entityManager->persist($steps[$code]);
        }
        $this->entityManager->flush();
        $store->storeStepInput($first, $steps['fetch'], 'read', 'transform', [['k' => 'fetched']], 1);
        $store->storeStepInput($first, $steps['transform'], 'read', 'write', [['k' => 'transformed']], 1);
        $store->storeStepInput($first, $steps['write'], 'read', 'verify', [['k' => 'written']], 1);
        $first->markCompleted();
        $this->entityManager->flush();

        $second = $this->relaunch('run-1', 'verify');
        $this->completeRun($second);

        $third = $this->relaunch($second, 'transform');

        self::assertSame(
            [['transform', 'fetch', [['k' => 'fetched']]]],
            $this->payloadsOf($third),
        );
    }

    private function relaunch(string $runId, string $stepCode): string
    {
        $handler = new JoinStep('h', 'H');
        $workflow = $this->createStub(WorkflowInterface::class);
        $workflow->method('definition')->willReturn(new WorkflowDefinition('fixture', 'Fixture', 'a', 'b', [
            new WorkflowStepDefinition('fetch', 'Fetch', WorkflowStepType::Read, $handler),
            new WorkflowStepDefinition('transform', 'Transform', WorkflowStepType::Read, $handler, ['fetch']),
            new WorkflowStepDefinition('write', 'Write', WorkflowStepType::Read, $handler, ['transform']),
            new WorkflowStepDefinition('verify', 'Verify', WorkflowStepType::Read, $handler, ['write']),
        ]));
        $registry = $this->registry();
        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturn(new Envelope(new \stdClass()));

        return (new WorkflowRelaunchService(
            registry: new SynchronizationRegistry([$workflow]),
            entityManager: $this->entityManager,
            messageBus: $messageBus,
            workflowRunRepository: new WorkflowRunRepository($registry),
            workflowStepRunRepository: new WorkflowStepRunRepository($registry),
            workflowPayloadRepository: new WorkflowPayloadRepository($registry),
            workflowPayloadStore: new WorkflowPayloadStore($this->entityManager),
            workflowExecutionLockManager: $this->createStub(WorkflowExecutionLockManagerInterface::class),
            workflowRelaunchPlanner: new WorkflowRelaunchPlanner(),
        ))->relaunch($runId, WorkflowRelaunchMode::Step, $stepCode);
    }

    private function completeRun(string $runId): void
    {
        $run = (new WorkflowRunRepository($this->registry()))->findOneByRunId($runId);
        self::assertNotNull($run);

        foreach ((new WorkflowStepRunRepository($this->registry()))->findByWorkflowRunOrdered($run) as $stepRun) {
            if (!$stepRun->status()->isCompleted()) {
                $stepRun->markRunning();
                $stepRun->markCompleted(processedCount: 1, successCount: 1, errorCount: 0);
            }
        }

        $run->markCompleted();
        $this->entityManager->flush();
    }

    /**
     * @return list<array{string, string, list<array<string, mixed>>}>
     */
    private function payloadsOf(string $runId): array
    {
        $run = (new WorkflowRunRepository($this->registry()))->findOneByRunId($runId);
        self::assertNotNull($run);
        $store = new WorkflowPayloadStore($this->entityManager);
        $payloads = [];

        foreach ((new WorkflowPayloadRepository($this->registry()))->findBy(['workflowRun' => $run]) as $payload) {
            $payloads[] = [$payload->targetStepName(), $payload->sourceStepRun()->stepName(), $store->load($payload)['records']];
        }

        return $payloads;
    }

    private function registry(): ManagerRegistry
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->entityManager);
        $registry->method('getManager')->willReturn($this->entityManager);

        return $registry;
    }
}
