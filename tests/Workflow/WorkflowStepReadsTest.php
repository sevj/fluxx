<?php

declare(strict_types=1);

namespace Fluxx\Tests\Workflow;

use Fluxx\Entity\Enum\WorkflowStepType;
use Fluxx\Workflow\Step\JoinStep;
use Fluxx\Workflow\Context\WorkflowContext;
use Fluxx\Workflow\Step\WorkflowStepInput;
use Fluxx\Workflow\WorkflowDefinition;
use Fluxx\Workflow\WorkflowStepDefinition;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkflowStepReadsTest extends TestCase
{
    #[Test]
    public function it_accepts_reads_on_transitive_ancestors(): void
    {
        $definition = $this->definition(['first']);

        self::assertSame(['first'], $definition->step('third')->reads());
    }

    #[Test]
    public function it_refuses_reads_on_a_step_that_is_not_an_ancestor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('reads "unrelated"');

        $this->definition(['unrelated']);
    }

    #[Test]
    public function it_refuses_reading_an_undeclared_ancestor(): void
    {
        $input = new WorkflowStepInput(declaredReads: ['first'], ancestorRecordsLoader: static fn (): array => []);

        $this->expectException(LogicException::class);

        $input->recordsFromAncestor('second');
    }

    #[Test]
    public function it_loads_declared_ancestors_on_demand(): void
    {
        $calls = [];
        $input = new WorkflowStepInput(declaredReads: ['first'], ancestorRecordsLoader: static function (string $code) use (&$calls): array {
            $calls[] = $code;

            return [['id' => 1]];
        });

        self::assertSame([], $calls);
        self::assertSame([['id' => 1]], $input->recordsFromAncestor('first'));
        self::assertSame(['first'], $calls);
    }

    #[Test]
    public function it_builds_a_join_step_that_does_nothing(): void
    {
        $join = WorkflowStepDefinition::join('done', 'Done', ['first', 'second']);
        $result = $join->handler()->execute(new WorkflowContext('w', 'W', 'a', 'b', 'run'), new WorkflowStepInput());

        self::assertSame(WorkflowStepType::Join, $join->typeEnum());
        self::assertSame('done', $join->handler()->code());
        self::assertSame(['first', 'second'], $join->dependsOn());
        self::assertSame([], $result->records());
    }

    /**
     * @param list<string> $reads
     */
    private function definition(array $reads): WorkflowDefinition
    {
        $handler = new JoinStep('h', 'H');

        return new WorkflowDefinition('w', 'W', 'a', 'b', [
            new WorkflowStepDefinition('first', 'First', WorkflowStepType::Read, $handler),
            new WorkflowStepDefinition('unrelated', 'Unrelated', WorkflowStepType::Read, $handler),
            new WorkflowStepDefinition('second', 'Second', WorkflowStepType::Read, $handler, ['first']),
            new WorkflowStepDefinition('third', 'Third', WorkflowStepType::Read, $handler, ['second'], reads: $reads),
        ]);
    }
}
