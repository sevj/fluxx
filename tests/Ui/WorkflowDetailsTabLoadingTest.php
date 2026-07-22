<?php

declare(strict_types=1);

namespace Fluxx\Tests\Ui;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use Fluxx\Repository\WorkflowRunRepository;
use Fluxx\Repository\WorkflowStepRunRepository;
use Fluxx\StepType\StepTypeRegistry;
use Fluxx\Tests\Fixture\FixtureWorkflow;
use Fluxx\Tests\Fixture\StubExecutableStep;
use Fluxx\Ui\WorkflowDetails;
use Fluxx\Workflow\SynchronizationRegistry;
use Fluxx\Workflow\WorkflowDefinition;
use Fluxx\Workflow\WorkflowStepDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkflowDetailsTabLoadingTest extends TestCase
{
    #[Test]
    public function it_loads_the_steps_tab_without_touching_runtime_repositories(): void
    {
        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->expects(self::never())->method('getManagerForClass');

        $details = new WorkflowDetails(
            registry: new SynchronizationRegistry([new FixtureWorkflow($this->createWorkflowDefinition())]),
            stepTypeRegistry: new StepTypeRegistry([]),
            workflowRunRepository: new WorkflowRunRepository($managerRegistry),
            workflowStepRunRepository: new WorkflowStepRunRepository($managerRegistry),
        );

        $view = $details->forTab(
            workflowCode: 'fixture_workflow',
            tab: 'steps',
            page: 3,
            statisticsRange: 'year',
        );

        self::assertCount(1, $view->stepRows());
        self::assertSame(3, $view->executionPage()->currentPage());
        self::assertSame(0, $view->executionPage()->totalItems());
        self::assertSame('year', $view->statistics()->selectedRange());
        self::assertCount(0, $view->statistics()->points());
    }

    #[Test]
    public function it_loads_the_statistics_tab_without_touching_execution_entities(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($this->createMock(AbstractMySQLPlatform::class));
        $connection->expects(self::exactly(2))
            ->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql, array $params): array {
                static $call = 0;
                ++$call;

                self::assertSame('fixture_workflow', $params['workflowName']);
                self::assertInstanceOf(\DateTimeImmutable::class, $params['startAt']);

                if ($call === 1) {
                    self::assertStringContainsString("DATE_FORMAT(created_at, '%Y-%m-%d')", $sql);

                    return [];
                }

                self::assertStringContainsString('GROUP BY workflow_step_run.step_name', $sql);

                return [];
            });
        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->with(
                self::stringContains('COUNT(*) AS run_count'),
                self::callback(static fn (array $params): bool => $params['workflowName'] === 'fixture_workflow' && $params['relaunchMarker'] === '%"relaunch":%'),
                self::isType('array'),
            )
            ->willReturn([
                'run_count' => 0,
                'failed_count' => 0,
                'partial_failed_count' => 0,
                'relaunch_count' => 0,
            ]);
        $connection->expects(self::once())
            ->method('fetchFirstColumn')
            ->with(
                self::stringContains('TIMESTAMPDIFF(MICROSECOND, started_at, finished_at) DIV 1000'),
                self::callback(static fn (array $params): bool => $params['workflowName'] === 'fixture_workflow'),
                self::isType('array'),
            )
            ->willReturn([]);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->with(
                self::stringContains('COUNT(DISTINCT workflow_step_run.workflow_run_id)'),
                self::callback(static fn (array $params): bool => $params['workflowName'] === 'fixture_workflow'),
                self::isType('array'),
            )
            ->willReturn(0);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('getClassMetadata')->willReturnCallback(static function (string $className): ClassMetadata {
            return new ClassMetadata($className);
        });

        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->method('getManagerForClass')->willReturn($entityManager);

        $details = new WorkflowDetails(
            registry: new SynchronizationRegistry([new FixtureWorkflow($this->createWorkflowDefinition())]),
            stepTypeRegistry: new StepTypeRegistry([]),
            workflowRunRepository: new WorkflowRunRepository($managerRegistry),
            workflowStepRunRepository: new WorkflowStepRunRepository($managerRegistry),
        );

        $view = $details->forTab(
            workflowCode: 'fixture_workflow',
            tab: 'statistics',
            page: 2,
            statisticsRange: 'month',
        );

        self::assertSame(2, $view->executionPage()->currentPage());
        self::assertSame(0, $view->executionPage()->totalItems());
        self::assertSame('month', $view->statistics()->selectedRange());
        self::assertCount(30, $view->statistics()->points());
    }

    private function createWorkflowDefinition(): WorkflowDefinition
    {
        $step = new StubExecutableStep('fetch_company', 'Fetch company');

        return new WorkflowDefinition(
            code: 'fixture_workflow',
            name: 'Fixture workflow',
            sourceSystem: 'source',
            targetSystem: 'target',
            steps: [
                new WorkflowStepDefinition(
                    code: $step->code(),
                    name: $step->name(),
                    type: 'fetch',
                    handler: $step,
                ),
            ],
        );
    }
}
