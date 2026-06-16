<?php

declare(strict_types=1);

namespace Fluxx\Tests\Command;

use Fluxx\Command\RetryWorkflowStepCommand;
use Fluxx\Operations\WorkflowRetryOperator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RetryWorkflowStepCommandTest extends TestCase
{
    #[Test]
    public function it_retries_a_run_from_a_specific_step(): void
    {
        $operator = $this->createMock(WorkflowRetryOperator::class);
        $operator->expects(self::once())
            ->method('retryStep')
            ->with('run-1', 'write_contacts', 'cli', 'Retry step', null)
            ->willReturn('run-2');

        $tester = new CommandTester(new RetryWorkflowStepCommand($operator));
        $exitCode = $tester->execute([
            'run-id' => 'run-1',
            'step-code' => 'write_contacts',
            '--reason' => 'Retry step',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Workflow step retried. New run ID: run-2', $tester->getDisplay());
    }
}
