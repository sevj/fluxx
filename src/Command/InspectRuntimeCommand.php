<?php

declare(strict_types=1);

namespace Fluxx\Command;

use Fluxx\Operations\RuntimeInspector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'fluxx:runtime:inspect', description: 'Inspect the live Fluxx runtime snapshot.')]
final class InspectRuntimeCommand extends Command
{
    public function __construct(
        private readonly RuntimeInspector $runtimeInspector,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table or json.', 'table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $snapshot = $this->runtimeInspector->snapshot();
        $format = (string) $input->getOption('format');

        if ($format === 'json') {
            $io->writeln((string) json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return (bool) ($snapshot['ok'] ?? false) ? Command::SUCCESS : Command::FAILURE;
        }

        if ($format !== 'table') {
            $io->error(sprintf('Unknown format "%s". Supported formats: table, json.', $format));

            return Command::INVALID;
        }

        if (($snapshot['ok'] ?? false) !== true) {
            $io->error((string) ($snapshot['error'] ?? 'Runtime snapshot failed.'));
        }

        $summary = is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [];
        $queue = is_array($snapshot['queue'] ?? null) ? $snapshot['queue'] : [];
        $workers = is_array($snapshot['workers'] ?? null) ? $snapshot['workers'] : [];
        $locks = is_array($snapshot['activeLocks'] ?? null) ? $snapshot['activeLocks'] : [];
        $messages = is_array($snapshot['messages'] ?? null) ? $snapshot['messages'] : [];

        $io->definitionList(
            ['Refreshed at' => (string) ($snapshot['refreshedAt'] ?? '-')],
            ['Backlog' => (string) ($summary['backlogCount'] ?? 0)],
            ['In flight' => (string) ($summary['inFlightCount'] ?? 0)],
            ['Consumers' => (string) ($summary['consumerCount'] ?? 0)],
            ['Active locks' => (string) ($summary['activeLockCount'] ?? 0)],
            ['Visible messages' => (string) ($summary['visibleMessageCount'] ?? 0)],
            ['Oldest pending (ms)' => $summary['oldestPendingAgeMs'] !== null ? (string) $summary['oldestPendingAgeMs'] : '-'],
            ['Transport' => (string) ($queue['name'] ?? 'fluxx')],
            ['Stream' => (string) ($queue['stream'] ?? '-')],
            ['Group' => (string) ($queue['group'] ?? '-')],
        );

        $io->section('Workers');
        if ($workers === []) {
            $io->text('No worker state available.');
        } else {
            $io->table(
                ['Name', 'State', 'Pending', 'Current', 'Last seen'],
                array_map(
                    static fn (array $worker): array => [
                        (string) ($worker['name'] ?? '-'),
                        (string) ($worker['state'] ?? '-'),
                        (string) ($worker['pendingCount'] ?? 0),
                        (string) ($worker['currentMessageLabel'] ?? '-'),
                        (string) ($worker['lastSeenAt'] ?? '-'),
                    ],
                    $workers,
                ),
            );
        }

        $io->section('Active Locks');
        if ($locks === []) {
            $io->text('No active workflow lock.');
        } else {
            $io->table(
                ['Workflow', 'Run ID', 'Scope', 'Lock key', 'Acquired'],
                array_map(
                    static fn (array $lock): array => [
                        (string) ($lock['workflowCode'] ?? '-'),
                        (string) ($lock['runId'] ?? '-'),
                        (string) ($lock['scope'] ?? '-'),
                        (string) ($lock['lockKey'] ?? '-'),
                        (string) ($lock['acquiredAt'] ?? '-'),
                    ],
                    $locks,
                ),
            );
        }

        $io->section('Messages');
        if ($messages === []) {
            $io->text('No visible queued message.');
        } else {
            $io->table(
                ['ID', 'State', 'Workflow', 'Run ID', 'Step', 'Consumer'],
                array_map(
                    static fn (array $message): array => [
                        (string) ($message['id'] ?? '-'),
                        (string) ($message['state'] ?? '-'),
                        (string) ($message['workflowCode'] ?? '-'),
                        (string) ($message['runId'] ?? '-'),
                        (string) ($message['stepCode'] ?? '-'),
                        (string) ($message['consumerName'] ?? '-'),
                    ],
                    $messages,
                ),
            );
        }

        return (bool) ($snapshot['ok'] ?? false) ? Command::SUCCESS : Command::FAILURE;
    }
}
