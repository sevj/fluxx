<?php

declare(strict_types=1);

namespace Fluxx\Tests\Command;

use Fluxx\Command\CancelWorkflowRunCommand;
use Fluxx\Operations\WorkflowCancelOperator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CancelWorkflowRunCommandTest extends TestCase
{
    #[Test]
    public function it_cancels_an_active_run(): void
    {
        $operator = $this->createMock(WorkflowCancelOperator::class);
        $operator->expects(self::once())
            ->method('cancelRun')
            ->with('run-1', 'cli', 'Stuck import', 'alice@example.com')
            ->willReturn(true);

        $tester = new CommandTester(new CancelWorkflowRunCommand($operator));
        $exitCode = $tester->execute([
            'run-id' => 'run-1',
            '--reason' => 'Stuck import',
            '--operator' => 'alice@example.com',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Workflow run "run-1" cancelled.', $tester->getDisplay());
    }

    #[Test]
    public function it_refuses_a_run_that_is_no_longer_active(): void
    {
        $operator = $this->createStub(WorkflowCancelOperator::class);
        $operator->method('cancelRun')->willReturn(false);

        $tester = new CommandTester(new CancelWorkflowRunCommand($operator));
        $exitCode = $tester->execute(['run-id' => 'run-1']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('is no longer active and cannot be cancelled', $tester->getDisplay());
    }

    #[Test]
    public function it_reports_an_unknown_run(): void
    {
        $operator = $this->createStub(WorkflowCancelOperator::class);
        $operator->method('cancelRun')->willThrowException(new RuntimeException('Workflow run "nope" was not found.'));

        $tester = new CommandTester(new CancelWorkflowRunCommand($operator));
        $exitCode = $tester->execute(['run-id' => 'nope']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Workflow run "nope" was not found.', $tester->getDisplay());
    }
}
