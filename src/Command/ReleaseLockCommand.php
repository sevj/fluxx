<?php

declare(strict_types=1);

namespace Fluxx\Command;

use Fluxx\Operations\StaleLockReleaser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'fluxx:runtime:release-lock', description: 'Release a Fluxx workflow execution lock by run id, or all stale locks.')]
final class ReleaseLockCommand extends Command
{
    public function __construct(
        private readonly StaleLockReleaser $releaser,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('run-id', null, InputOption::VALUE_REQUIRED, 'Release the active lock owned by this run id.');
        $this->addOption('stale', null, InputOption::VALUE_NONE, 'Release all stale locks (terminal/gone owner run or no live worker heartbeat).');
        $this->addOption('stale-heartbeat', null, InputOption::VALUE_REQUIRED, 'Heartbeat staleness threshold in seconds for --stale.', 120);
        $this->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Reason recorded on the released lock (single run mode).', 'manual_release');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $runId = $input->getOption('run-id');
        $stale = (bool) $input->getOption('stale');

        if (!$stale && $runId === null) {
            $io->error('Pass --run-id=<runId> to release a single lock, or --stale to release all stale locks.');

            return Command::INVALID;
        }

        try {
            if ($stale) {
                $released = $this->releaser->releaseStale(staleHeartbeatSeconds: (int) $input->getOption('stale-heartbeat'));

                if ($released === []) {
                    $io->text('No stale lock was found.');

                    return Command::SUCCESS;
                }

                $io->table(
                    ['Run ID', 'Lock key', 'Reason'],
                    array_map(
                        static fn (array $lock): array => [$lock['runId'], $lock['lockKey'], $lock['reason']],
                        $released,
                    ),
                );
                $io->success(sprintf('Released %d stale lock(s).', count($released)));

                return Command::SUCCESS;
            }

            $released = $this->releaser->releaseForRun((string) $runId, (string) $input->getOption('reason'));

            if (!$released) {
                $io->text(sprintf('No active lock was found for run "%s".', (string) $runId));

                return Command::SUCCESS;
            }

            $io->success(sprintf('Released the active lock for run "%s".', (string) $runId));

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }
    }
}
