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
use Fluxx\Entity\WorkflowRun;
use Fluxx\Repository\WorkflowRunRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Runs against SQLite by default. Set FLUXX_TEST_DATABASE_URL to use another database:
 * its Fluxx tables are dropped and created again, so point it at a throwaway one.
 */
final class StartedActiveRunsDatabaseTest extends TestCase
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
    public function it_leaves_out_runs_still_waiting_for_a_worker(): void
    {
        $this->persistRun('pending');
        $this->persistRun('relaunched')->markRelaunched();
        $this->persistRun('running')->markRunning();
        $this->persistRun('retrying')->markRetrying();
        $this->persistRun('completed')->markCompleted();
        $this->entityManager->flush();

        $runIds = array_map(
            static fn (WorkflowRun $run): string => $run->runId(),
            (new WorkflowRunRepository($this->registry()))->findStartedActiveRuns(),
        );

        self::assertSame(['running', 'retrying'], $runIds);
    }

    private function persistRun(string $runId): WorkflowRun
    {
        $run = new WorkflowRun($runId, 'fixture', 'a', 'b', 'manual');
        $this->entityManager->persist($run);

        return $run;
    }

    private function registry(): ManagerRegistry
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->entityManager);
        $registry->method('getManager')->willReturn($this->entityManager);

        return $registry;
    }
}
