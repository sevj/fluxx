<?php

declare(strict_types=1);

namespace Fluxx\Tests\Ui;

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
