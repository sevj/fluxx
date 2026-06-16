<?php

declare(strict_types=1);

namespace Fluxx\Tests\Workflow;

use Fluxx\Entity\Enum\WorkflowExecutionLockScope;
use Fluxx\Workflow\Context\WorkflowContext;
use Fluxx\Workflow\Lock\WorkflowExecutionLockConfiguration;
use Fluxx\Workflow\Retry\WorkflowRetryBackoffStrategy;
use Fluxx\Workflow\Retry\WorkflowRetryPolicy;
use Fluxx\Workflow\Result\WorkflowStepResult;
use Fluxx\Workflow\Step\ExecutableWorkflowStepInterface;
use Fluxx\Workflow\Step\IdempotentWorkflowStepInterface;
use Fluxx\Workflow\Step\WorkflowStepIdempotence;
use Fluxx\Workflow\Step\WorkflowStepInput;
use Fluxx\Workflow\WorkflowDefinition;
use Fluxx\Workflow\WorkflowStepDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkflowDefinitionTest extends TestCase
{
    #[Test]
    public function it_keeps_lock_configuration_and_step_idempotence_metadata(): void
    {
        $definition = new WorkflowDefinition(
            code: 'contacts',
            name: 'Contacts',
            sourceSystem: 'CSV',
            targetSystem: 'Hubspot',
            steps: [
                new WorkflowStepDefinition(
                    code: 'contacts_write',
                    name: 'Contacts Write',
                    type: 'write',
                    handler: new DummyIdempotentStep(),
                    idempotence: new WorkflowStepIdempotence('step_input_key'),
                ),
            ],
            lock: new WorkflowExecutionLockConfiguration(
                scope: WorkflowExecutionLockScope::WorkflowSourceTarget,
                staleTimeoutSeconds: 300,
            ),
            retryPolicy: new WorkflowRetryPolicy(
                maxRetries: 3,
                delaySeconds: 30,
                backoffStrategy: WorkflowRetryBackoffStrategy::Linear,
            ),
        );

        self::assertNotNull($definition->lock());
        self::assertSame(WorkflowExecutionLockScope::WorkflowSourceTarget, $definition->lock()?->scope());
        self::assertSame('step_input_key', $definition->step('contacts_write')->idempotence()?->strategy());
        self::assertSame(3, $definition->retryPolicy()?->maxRetries());
    }
}

final class DummyIdempotentStep implements ExecutableWorkflowStepInterface, IdempotentWorkflowStepInterface
{
    public function code(): string
    {
        return 'contacts_write';
    }

    public function name(): string
    {
        return 'Contacts Write';
    }

    public function execute(WorkflowContext $context, WorkflowStepInput $input): WorkflowStepResult
    {
        return new WorkflowStepResult();
    }

    public function idempotenceKey(WorkflowContext $context, WorkflowStepInput $input): ?string
    {
        return 'dummy';
    }
}
