<?php

declare(strict_types=1);

namespace Fluxx\Tests\Command;

use Fluxx\Command\InspectRuntimeCommand;
use Fluxx\Operations\RuntimeInspector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class InspectRuntimeCommandTest extends TestCase
{
    #[Test]
    public function it_renders_runtime_tables(): void
    {
        $inspector = $this->createMock(RuntimeInspector::class);
        $inspector->method('snapshot')->willReturn([
            'ok' => true,
            'refreshedAt' => '2026-06-16T12:00:00+00:00',
            'summary' => [
                'backlogCount' => 4,
                'inFlightCount' => 1,
                'consumerCount' => 2,
                'activeLockCount' => 1,
                'visibleMessageCount' => 1,
                'oldestPendingAgeMs' => 1200,
            ],
            'queue' => [
                'name' => 'fluxx',
                'stream' => 'fluxx',
                'group' => 'worker',
            ],
            'workers' => [
                ['name' => 'worker-1', 'state' => 'processing', 'pendingCount' => 1, 'currentMessageLabel' => 'run-1/write', 'lastSeenAt' => '2026-06-16T12:00:00+00:00'],
            ],
            'activeLocks' => [
                ['workflowCode' => 'contacts', 'runId' => 'run-1', 'scope' => 'workflow_source', 'lockKey' => 'contacts:CSV', 'acquiredAt' => '2026-06-16T11:59:00+00:00'],
            ],
            'messages' => [
                ['id' => '1-0', 'state' => 'queued', 'workflowCode' => 'contacts', 'runId' => 'run-1', 'stepCode' => 'write_contacts', 'consumerName' => null],
            ],
        ]);

        $tester = new CommandTester(new InspectRuntimeCommand($inspector));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Workers', $tester->getDisplay());
        self::assertStringContainsString('worker-1', $tester->getDisplay());
        self::assertStringContainsString('contacts', $tester->getDisplay());
    }

    #[Test]
    public function it_supports_json_output(): void
    {
        $inspector = $this->createMock(RuntimeInspector::class);
        $inspector->method('snapshot')->willReturn([
            'ok' => true,
            'summary' => ['backlogCount' => 0],
        ]);

        $tester = new CommandTester(new InspectRuntimeCommand($inspector));
        $exitCode = $tester->execute(['--format' => 'json']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('"backlogCount": 0', $tester->getDisplay());
    }
}
