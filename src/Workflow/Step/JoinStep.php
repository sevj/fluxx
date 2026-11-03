<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Step;

use Fluxx\Workflow\Context\WorkflowContext;
use Fluxx\Workflow\Result\WorkflowStepResult;

/**
 * Handler of a virtual step that does no work and produces no records.
 *
 * @see \Fluxx\Workflow\WorkflowStepDefinition::join()
 */
final readonly class JoinStep implements ExecutableWorkflowStepInterface
{
    public function __construct(
        private string $code,
        private string $name,
    ) {
    }

    public static function staticCode(): string
    {
        return 'join';
    }

    public function code(): string
    {
        return $this->code;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function execute(WorkflowContext $context, WorkflowStepInput $input): WorkflowStepResult
    {
        return new WorkflowStepResult();
    }
}
