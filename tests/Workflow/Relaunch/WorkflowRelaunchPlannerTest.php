<?php

declare(strict_types=1);

namespace Fluxx\Tests\Workflow\Relaunch;

use Fluxx\Workflow\Result\WorkflowStepResult;
use Fluxx\Workflow\Relaunch\WorkflowRelaunchMode;
use Fluxx\Workflow\Relaunch\WorkflowRelaunchPlanner;
use Fluxx\Workflow\Step\ExecutableWorkflowStepInterface;
use Fluxx\Workflow\Step\WorkflowStepInput;
use Fluxx\Workflow\WorkflowDefinition;
use Fluxx\Workflow\WorkflowStepDefinition;
use Fluxx\Workflow\Context\WorkflowContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkflowRelaunchPlannerTest extends TestCase
{
    #[Test]
    public function it_plans_a_full_relaunch_from_roots(): void
    {
        $plan = (new WorkflowRelaunchPlanner())->plan($this->definition(), WorkflowRelaunchMode::Full);

        self::assertSame(['read_contacts'], $plan->entryStepCodes());
        self::assertSame(
            ['read_contacts', 'split_contacts', 'transform_company', 'transform_person', 'link_contacts'],
            $plan->targetStepCodes(),
        );
        self::assertSame([], $plan->preservedStepCodes());
    }

    #[Test]
    public function it_plans_a_step_relaunch_with_preserved_ancestors(): void
    {
        $plan = (new WorkflowRelaunchPlanner())->plan($this->definition(), WorkflowRelaunchMode::Step, 'transform_person');

        self::assertSame(['transform_person'], $plan->entryStepCodes());
        self::assertSame(['transform_person', 'link_contacts'], $plan->targetStepCodes());
        self::assertSame(['read_contacts', 'split_contacts'], $plan->preservedStepCodes());
    }

    private function definition(): WorkflowDefinition
    {
        $handler = new class implements ExecutableWorkflowStepInterface {
            public function code(): string { return 'stub'; }
            public function name(): string { return 'Stub'; }
            public function execute(WorkflowContext $context, WorkflowStepInput $input): WorkflowStepResult
            {
                return new WorkflowStepResult();
            }
        };

        return new WorkflowDefinition(
            code: 'contacts',
            name: 'Contacts',
            sourceSystem: 'CSV',
            targetSystem: 'Hubspot',
            steps: [
                new WorkflowStepDefinition('read_contacts', 'Read Contacts', 'read', $handler),
                new WorkflowStepDefinition('split_contacts', 'Split Contacts', 'splitter', $handler, ['read_contacts']),
                new WorkflowStepDefinition('transform_company', 'Transform Company', 'transform', $handler, ['split_contacts']),
                new WorkflowStepDefinition('transform_person', 'Transform Person', 'transform', $handler, ['split_contacts']),
                new WorkflowStepDefinition('link_contacts', 'Link Contacts', 'linker', $handler, ['transform_company', 'transform_person']),
            ],
        );
    }
}
