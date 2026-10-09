<?php

declare(strict_types=1);

namespace Fluxx\Tests\Command;

use Fluxx\Command\RunWorkflowCommand;
use Fluxx\Workflow\FluxxEngineInterface;
use Fluxx\Workflow\SynchronousFluxxEngineInterface;
use Fluxx\Workflow\SynchronousWorkflowResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RunWorkflowCommandTest extends TestCase
{
    #[Test]
    public function it_dispatches_a_workflow_with_arbitrary_parameters(): void
    {
        $engine = $this->createMock(FluxxEngineInterface::class);
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

        $syncEngine = $this->createMock(SynchronousFluxxEngineInterface::class);
        $syncEngine->expects(self::never())->method('runWithResult');

        $tester = new CommandTester(new RunWorkflowCommand($engine, $syncEngine));
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
        $engine = $this->createMock(FluxxEngineInterface::class);
        $engine->expects(self::never())->method('run');

        $syncEngine = $this->createMock(SynchronousFluxxEngineInterface::class);
        $syncEngine->expects(self::never())->method('runWithResult');

        $tester = new CommandTester(new RunWorkflowCommand($engine, $syncEngine));
        $exitCode = $tester->execute([
            'workflow' => 'contacts',
            '--parameter' => ['offset'],
        ]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('must use the format key=value', $tester->getDisplay());
    }

    #[Test]
    public function it_runs_synchronously_and_outputs_records(): void
    {
        $engine = $this->createMock(FluxxEngineInterface::class);
        $engine->expects(self::never())->method('run');

        $syncEngine = $this->createMock(SynchronousFluxxEngineInterface::class);
        $syncEngine->expects(self::once())
            ->method('runWithResult')
            ->with('contacts', 'manual', null, ['parameters' => ['dry' => true]])
            ->willReturn(new SynchronousWorkflowResult(runId: 'sync-1', records: [['name' => 'ACME']]));

        $tester = new CommandTester(new RunWorkflowCommand($engine, $syncEngine));
        $exitCode = $tester->execute([
            'workflow' => 'contacts',
            '--sync' => null,
            '--parameter' => ['dry=true'],
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('completed synchronously', $tester->getDisplay());
        self::assertStringContainsString('Run ID: sync-1', $tester->getDisplay());
        self::assertStringContainsString('"name": "ACME"', $tester->getDisplay());
    }
}
