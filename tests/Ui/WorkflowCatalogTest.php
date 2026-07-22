<?php

declare(strict_types=1);

namespace Fluxx\Tests\Ui;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Repository\WorkflowRunRepository;
use Fluxx\Tests\Fixture\FixtureWorkflow;
use Fluxx\Ui\WorkflowCatalog;
use Fluxx\Workflow\SynchronizationRegistry;
use Fluxx\Workflow\WorkflowDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkflowCatalogTest extends TestCase
{
    #[Test]
    public function it_fetches_aggregated_statistics_for_matching_workflows_in_all_view(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(
                self::stringContains('GROUP BY workflow_name'),
                [
                    'workflowNames' => ['alpha'],
                    'errorStatuses' => ['failed', 'partially_failed'],
                ],
                [
                    'workflowNames' => ArrayParameterType::STRING,
                    'errorStatuses' => ArrayParameterType::STRING,
                ],
            )
            ->willReturn([
                [
                    'workflow_name' => 'alpha',
                    'execution_count' => 11,
                    'error_count' => 2,
                    'last_execution_at' => '2026-07-22 08:10:00',
                    'last_error_at' => '2026-07-22 08:12:00',
                ],
            ]);

        $catalog = new WorkflowCatalog(
            registry: new SynchronizationRegistry([
                new FixtureWorkflow($this->definition('charlie', 'Charlie workflow')),
                new FixtureWorkflow($this->definition('alpha', 'Alpha workflow')),
            ]),
            workflowRunRepository: $this->createRepository($connection),
        );

        $items = $catalog->all('alpha');

        self::assertCount(1, $items);
        self::assertSame('alpha', $items[0]->code());
        self::assertSame(11, $items[0]->executionCount());
        self::assertSame(2, $items[0]->errorCount());
    }

    #[Test]
    public function it_fetches_aggregated_statistics_only_for_the_requested_page(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->with(
                self::stringContains('GROUP BY workflow_name'),
                [
                    'workflowNames' => ['bravo'],
                    'errorStatuses' => ['failed', 'partially_failed'],
                ],
                [
                    'workflowNames' => ArrayParameterType::STRING,
                    'errorStatuses' => ArrayParameterType::STRING,
                ],
            )
            ->willReturn([
                [
                    'workflow_name' => 'bravo',
                    'execution_count' => 7,
                    'error_count' => 1,
                    'last_execution_at' => '2026-07-22 09:15:00',
                    'last_error_at' => '2026-07-22 09:20:00',
                ],
            ]);

        $catalog = new WorkflowCatalog(
            registry: new SynchronizationRegistry([
                new FixtureWorkflow($this->definition('charlie', 'Charlie workflow')),
                new FixtureWorkflow($this->definition('bravo', 'Bravo workflow')),
                new FixtureWorkflow($this->definition('alpha', 'Alpha workflow')),
            ]),
            workflowRunRepository: $this->createRepository($connection),
        );

        $page = $catalog->paginate(page: 2, perPage: 1);

        self::assertSame(2, $page->currentPage());
        self::assertSame(3, $page->totalItems());
        self::assertSame(3, $page->totalPages());
        self::assertCount(1, $page->items());
        self::assertSame('bravo', $page->items()[0]->code());
        self::assertSame(7, $page->items()[0]->executionCount());
        self::assertSame(1, $page->items()[0]->errorCount());
        self::assertEquals(new DateTimeImmutable('2026-07-22 09:15:00'), $page->items()[0]->lastExecutionAt());
        self::assertEquals(new DateTimeImmutable('2026-07-22 09:20:00'), $page->items()[0]->lastErrorAt());
    }

    private function createRepository(Connection $connection): WorkflowRunRepository
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getClassMetadata')
            ->with(WorkflowRun::class)
            ->willReturn(new ClassMetadata(WorkflowRun::class));
        $entityManager->method('getConnection')->willReturn($connection);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')
            ->with(WorkflowRun::class)
            ->willReturn($entityManager);

        return new WorkflowRunRepository($registry);
    }

    private function definition(string $code, string $name): WorkflowDefinition
    {
        return new WorkflowDefinition(
            code: $code,
            name: $name,
            sourceSystem: 'source',
            targetSystem: 'target',
            steps: [],
        );
    }
}
