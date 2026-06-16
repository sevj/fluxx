<?php

declare(strict_types=1);

namespace Fluxx\Tests\Command;

use Fluxx\Command\RetryWorkflowRunCommand;
use Fluxx\Operations\WorkflowRetryOperator;
use Fluxx\Workflow\Lock\WorkflowExecutionLockConflict;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RetryWorkflowRunCommandTest extends TestCase
{
    #[Test]
    public function it_retries_a_run(): void
    {
        $operator = $this->createMock(WorkflowRetryOperator::class);
        $operator->expects(self::once())
            ->method('retryRun')
            ->with('run-1', 'cli', 'Operator retry', 'alice@example.com')
            ->willReturn('run-2');

        $tester = new CommandTester(new RetryWorkflowRunCommand($operator));
        $exitCode = $tester->execute([
            'run-id' => 'run-1',
            '--reason' => 'Operator retry',
            '--operator' => 'alice@example.com',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('New run ID: run-2', $tester->getDisplay());
    }

    #[Test]
    public function it_surfaces_lock_conflicts(): void
    {
        $operator = $this->createMock(WorkflowRetryOperator::class);
        $operator->method('retryRun')
            ->willThrowException(new WorkflowExecutionLockConflict('contacts', 'contacts:CSV', 'run-locked'));

        $tester = new CommandTester(new RetryWorkflowRunCommand($operator));
        $exitCode = $tester->execute([
            'run-id' => 'run-1',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('is locked by run "run-locked"', $tester->getDisplay());
    }
}
