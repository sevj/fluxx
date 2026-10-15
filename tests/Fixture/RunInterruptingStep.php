<?php

declare(strict_types=1);

namespace Fluxx\Tests\Fixture;

use Fluxx\Entity\WorkflowRun;
use Fluxx\Workflow\Context\WorkflowContext;
use Fluxx\Workflow\Result\WorkflowStepResult;
use Fluxx\Workflow\Step\ExecutableWorkflowStepInterface;
use Fluxx\Workflow\Step\WorkflowStepInput;

/**
 * Marks its owning run as terminal while the step is mid-flight, simulating an
 * orphan watchdog (or another actor) finalizing the run between two actions.
 */
final class RunInterruptingStep implements ExecutableWorkflowStepInterface
{
    public function __construct(
        private readonly WorkflowRun $workflowRun,
    ) {
    }

    public function code(): string
    {
        return 'run_interrupting_step';
    }

    public function name(): string
    {
        return 'Run interrupting step';
    }

    public static function staticCode(): string
    {
        return 'run_interrupting_step';
    }

    public function execute(WorkflowContext $context, WorkflowStepInput $input): WorkflowStepResult
    {
        $this->workflowRun->markFailed('Execution interrupted automatically: no worker activity.');

        return new WorkflowStepResult();
    }
}
