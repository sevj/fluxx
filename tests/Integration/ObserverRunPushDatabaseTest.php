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
use Fluxx\Entity\Enum\WorkflowRunStatus;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;
use Fluxx\Observer\ObserverClient;
use Fluxx\Observer\ObserverPushException;
use Fluxx\Observer\ObserverRunPusher;
use Fluxx\Observer\RunReportPayloadFactory;
use Fluxx\Repository\FluxxSettingRepository;
use Fluxx\Repository\WorkflowRunRepository;
use Fluxx\Repository\WorkflowStepRunRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Runs against SQLite by default. Set FLUXX_TEST_DATABASE_URL to use another database:
 * its Fluxx tables are dropped and created again, so point it at a throwaway one.
 */
final class ObserverRunPushDatabaseTest extends TestCase
{
    private Connection $connection;
    private EntityManager $entityManager;
    private SchemaTool $schemaTool;

    /** @var list<\Doctrine\ORM\Mapping\ClassMetadata<object>> */
    private array $metadata;

    /** @var list<list<array<string, mixed>>> reports of each request the observer received */
    private array $receivedBatches = [];

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
    public function it_pushes_runs_changed_since_the_last_push_and_every_active_run(): void
    {
        $this->settingRepository()->saveValue('observer.run_push', ['last_push_at' => (new DateTimeImmutable('-1 hour'))->format(DATE_ATOM)]);
        $this->storeRun('finished-recently', WorkflowRunStatus::Completed, createdAt: '-3 hours', finishedAt: '-30 minutes', processed: 5);
        $this->storeRun('finished-long-ago', WorkflowRunStatus::Completed, createdAt: '-3 hours', finishedAt: '-2 hours');
        $this->storeRun('running-for-hours', WorkflowRunStatus::Running, createdAt: '-5 hours');
        $this->storeRun('pruned-recently', WorkflowRunStatus::PayloadsPruned, createdAt: '-10 minutes', finishedAt: '-5 minutes');
        $this->storeRun('created-recently', WorkflowRunStatus::Pending, createdAt: '-1 minute');

        $report = $this->pusher($this->acceptingObserver())->push();

        self::assertSame(['finished-recently', 'running-for-hours', 'created-recently'], array_column($this->receivedBatches[0], 'runId'));
        self::assertSame(5, $this->receivedBatches[0][0]['processedCount']);
        self::assertSame(3, $report->pushedRunCount);
        self::assertSame([], $report->rejectedRuns);
        self::assertEqualsWithDelta((new DateTimeImmutable('-62 minutes'))->getTimestamp(), $report->since->getTimestamp(), 5);
        self::assertEqualsWithDelta(time(), (new DateTimeImmutable($this->settingRepository()->findValue('observer.run_push')['last_push_at']))->getTimestamp(), 5);
    }

    #[Test]
    public function the_first_push_goes_back_one_day(): void
    {
        $this->storeRun('yesterday-morning', WorkflowRunStatus::Completed, createdAt: '-30 hours', finishedAt: '-29 hours');
        $this->storeRun('this-morning', WorkflowRunStatus::Failed, createdAt: '-3 hours', finishedAt: '-2 hours');

        $this->pusher($this->acceptingObserver())->push();

        self::assertSame(['this-morning'], array_column($this->receivedBatches[0], 'runId'));
    }

    #[Test]
    public function an_explicit_since_overrides_the_cursor(): void
    {
        $this->settingRepository()->saveValue('observer.run_push', ['last_push_at' => (new DateTimeImmutable())->format(DATE_ATOM)]);
        $this->storeRun('last-week', WorkflowRunStatus::Completed, createdAt: '-7 days', finishedAt: '-7 days');

        $this->pusher($this->acceptingObserver())->push(new DateTimeImmutable('-8 days'));

        self::assertSame(['last-week'], array_column($this->receivedBatches[0], 'runId'));
    }

    #[Test]
    public function it_sends_large_volumes_in_batches(): void
    {
        for ($index = 1; $index <= 150; ++$index) {
            $this->storeRun(sprintf('run-%03d', $index), WorkflowRunStatus::Completed, createdAt: '-10 minutes', finishedAt: '-5 minutes', flush: false);
        }
        $this->entityManager->flush();

        $report = $this->pusher($this->acceptingObserver())->push();

        self::assertSame([100, 50], array_map(count(...), $this->receivedBatches));
        self::assertSame(150, count(array_unique(array_merge(...array_map(static fn ($batch) => array_column($batch, 'runId'), $this->receivedBatches)))));
        self::assertSame(150, $report->pushedRunCount);
    }

    #[Test]
    public function it_names_the_runs_the_observer_refused(): void
    {
        $this->storeRun('accepted', WorkflowRunStatus::Completed, createdAt: '-10 minutes', finishedAt: '-5 minutes');
        $this->storeRun('refused', WorkflowRunStatus::Completed, createdAt: '-10 minutes', finishedAt: '-5 minutes');
        $observer = new MockHttpClient(new MockResponse(
            '{"accepted":1,"rejected":1,"errors":[{"index":1,"error":"Unknown status."}]}',
            ['http_code' => 422],
        ));

        $report = $this->pusher($observer)->push();

        self::assertSame(1, $report->pushedRunCount);
        self::assertSame([['runId' => 'refused', 'error' => 'Unknown status.']], $report->rejectedRuns);
        self::assertNotNull($this->settingRepository()->findValue('observer.run_push'));
    }

    #[Test]
    public function a_failed_delivery_keeps_the_cursor_for_the_next_push(): void
    {
        $lastPushAt = (new DateTimeImmutable('-1 hour'))->format(DATE_ATOM);
        $this->settingRepository()->saveValue('observer.run_push', ['last_push_at' => $lastPushAt]);
        $this->storeRun('finished-recently', WorkflowRunStatus::Completed, createdAt: '-10 minutes', finishedAt: '-5 minutes');

        try {
            $this->pusher(new MockHttpClient(new MockResponse('', ['http_code' => 503])))->push();
            self::fail('The push should have failed.');
        } catch (ObserverPushException) {
        }

        self::assertSame(['last_push_at' => $lastPushAt], $this->settingRepository()->findValue('observer.run_push'));
    }

    private function storeRun(
        string $runId,
        WorkflowRunStatus $status,
        string $createdAt,
        ?string $finishedAt = null,
        int $processed = 0,
        bool $flush = true,
    ): void {
        $run = new WorkflowRun($runId, 'contacts', 'Eogile', 'Hubspot', 'webhook');
        $run->markRunning();
        $step = new WorkflowStepRun($run, 'read', 'read_contacts', 1);
        $step->markCompleted(processedCount: $processed, successCount: $processed);
        $this->entityManager->persist($run);
        $this->entityManager->persist($step);
        $this->entityManager->flush();

        $this->connection->update('fluxx_workflow_run', [
            'status' => $status->value,
            'created_at' => (new DateTimeImmutable($createdAt))->format('Y-m-d H:i:s'),
            'started_at' => (new DateTimeImmutable($createdAt))->format('Y-m-d H:i:s'),
            'finished_at' => $finishedAt !== null ? (new DateTimeImmutable($finishedAt))->format('Y-m-d H:i:s') : null,
        ], ['run_id' => $runId]);

        if ($flush) {
            $this->entityManager->clear();
        }
    }

    private function acceptingObserver(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $reports = json_decode($options['body'], true, flags: JSON_THROW_ON_ERROR)['reports'];
            $this->receivedBatches[] = $reports;

            return new MockResponse(json_encode(['accepted' => count($reports), 'rejected' => 0, 'errors' => []], JSON_THROW_ON_ERROR));
        });
    }

    private function pusher(MockHttpClient $observer): ObserverRunPusher
    {
        return new ObserverRunPusher(
            new ObserverClient($observer, 'https://observer.example.com', 'secret', 5),
            new RunReportPayloadFactory(),
            new WorkflowRunRepository($this->registry()),
            new WorkflowStepRunRepository($this->registry()),
            $this->settingRepository(),
            $this->entityManager,
        );
    }

    private function settingRepository(): FluxxSettingRepository
    {
        return new FluxxSettingRepository($this->registry());
    }

    private function registry(): ManagerRegistry
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->entityManager);
        $registry->method('getManager')->willReturn($this->entityManager);

        return $registry;
    }
}
