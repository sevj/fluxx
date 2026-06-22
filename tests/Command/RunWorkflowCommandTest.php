<?php

declare(strict_types=1);

namespace Fluxx\Tests\Command;

use Fluxx\Command\RunWorkflowCommand;
use Fluxx\Workflow\FluxxEngine;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RunWorkflowCommandTest extends TestCase
{
    #[Test]
    public function it_dispatches_a_workflow_with_arbitrary_parameters(): void
    {
        $engine = $this->createMock(FluxxEngine::class);
        $engine->expects(self::once())
            ->method('run')
            ->with(
                'contacts',
                'manual',
                'nightly-1',
                [
                    'parameters' => [
                        'offset' => 10,
                        'limit' => 20,
                        'dryRun' => true,
                        'filters' => ['status' => 'active'],
                    ],
                ],
            )
            ->willReturn('run-1');

        $tester = new CommandTester(new RunWorkflowCommand($engine));
        $exitCode = $tester->execute([
            'workflow' => 'contacts',
            '--batch-id' => 'nightly-1',
            '--parameter' => [
                'offset=10',
                'limit=20',
                'dryRun=true',
                'filters={"status":"active"}',
            ],
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Run ID: run-1', $tester->getDisplay());
    }

    #[Test]
    public function it_rejects_invalid_parameter_syntax(): void
    {
        $engine = $this->createMock(FluxxEngine::class);
        $engine->expects(self::never())->method('run');

        $tester = new CommandTester(new RunWorkflowCommand($engine));
        $exitCode = $tester->execute([
            'workflow' => 'contacts',
            '--parameter' => ['offset'],
        ]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('must use the format key=value', $tester->getDisplay());
    }
}
