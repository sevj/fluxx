<?php

declare(strict_types=1);

namespace Fluxx\Tests\Workflow\Runtime;

use Fluxx\Entity\Enum\WorkflowRunStatus;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;
use Fluxx\Workflow\Context\WorkflowContext;
use Fluxx\Workflow\Result\WorkflowStepResult;
use Fluxx\Workflow\Runtime\WorkflowRunCompletionDecider;
use Fluxx\Workflow\Step\ExecutableWorkflowStepInterface;
use Fluxx\Workflow\Step\WorkflowStepInput;
use Fluxx\Workflow\WorkflowDefinition;
use Fluxx\Workflow\WorkflowStepDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkflowRunCompletionDeciderTest extends TestCase
{
    #[Test]
    public function it_keeps_the_run_active_while_another_branch_can_still_progress(): void
    {
        $definition = $this->branchingDefinition();
        $steps = [
            'read' => $this->completedStepRun('run-1', 'read', 1),
            'branch_a' => $this->failedStepRun('run-1', 'branch_a', 2),
            'branch_b' => $this->pendingStepRun('run-1', 'branch_b', 3),
            'link' => $this->pendingStepRun('run-1', 'link', 4),
        ];

        $decision = (new WorkflowRunCompletionDecider())->decide(
            $definition,
            $steps,
            ['read', 'branch_a', 'branch_b', 'link'],
        );

        self::assertNull($decision);
    }

    #[Test]
    public function it_marks_the_run_partially_failed_when_one_branch_failed_and_the_other_completed(): void
    {
        $definition = $this->branchingDefinition();
        $steps = [
            'read' => $this->completedStepRun('run-1', 'read', 1),
            'branch_a' => $this->failedStepRun('run-1', 'branch_a', 2),
            'branch_b' => $this->completedStepRun('run-1', 'branch_b', 3),
            'link' => $this->pendingStepRun('run-1', 'link', 4),
        ];

        $decision = (new WorkflowRunCompletionDecider())->decide(
            $definition,
            $steps,
            ['read', 'branch_a', 'branch_b', 'link'],
        );

        self::assertSame(WorkflowRunStatus::PartiallyFailed, $decision);
    }

    #[Test]
    public function it_marks_the_run_failed_when_no_step_completed_successfully(): void
    {
        $definition = $this->branchingDefinition();
        $steps = [
            'read' => $this->failedStepRun('run-1', 'read', 1),
            'branch_a' => $this->pendingStepRun('run-1', 'branch_a', 2),
            'branch_b' => $this->pendingStepRun('run-1', 'branch_b', 3),
            'link' => $this->pendingStepRun('run-1', 'link', 4),
        ];

        $decision = (new WorkflowRunCompletionDecider())->decide(
            $definition,
            $steps,
            ['read', 'branch_a', 'branch_b', 'link'],
        );

        self::assertSame(WorkflowRunStatus::Failed, $decision);
    }

    private function branchingDefinition(): WorkflowDefinition
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
                new WorkflowStepDefinition('read', 'Read', 'read', $handler),
                new WorkflowStepDefinition('branch_a', 'Branch A', 'transform', $handler, ['read']),
                new WorkflowStepDefinition('branch_b', 'Branch B', 'transform', $handler, ['read']),
                new WorkflowStepDefinition('link', 'Link', 'linker', $handler, ['branch_a', 'branch_b']),
            ],
        );
    }

    private function pendingStepRun(string $runId, string $stepName, int $position): WorkflowStepRun
    {
        return new WorkflowStepRun($this->workflowRun($runId), 'transform', $stepName, $position);
    }

    private function completedStepRun(string $runId, string $stepName, int $position): WorkflowStepRun
    {
        $stepRun = new WorkflowStepRun($this->workflowRun($runId), 'transform', $stepName, $position);
        $stepRun->markRunning();
        $stepRun->markCompleted(1, 1);

        return $stepRun;
    }

    private function failedStepRun(string $runId, string $stepName, int $position): WorkflowStepRun
    {
        $stepRun = new WorkflowStepRun($this->workflowRun($runId), 'transform', $stepName, $position);
        $stepRun->markRunning();
        $stepRun->markFailed('boom', errorCount: 1, errorPayload: ['category' => 'technical']);

        return $stepRun;
    }

    private function workflowRun(string $runId): WorkflowRun
    {
        return new WorkflowRun(
            runId: $runId,
            workflowName: 'contacts',
            sourceSystem: 'CSV',
            targetSystem: 'Hubspot',
            trigger: 'manual',
        );
    }
}
