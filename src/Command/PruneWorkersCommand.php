<?php

declare(strict_types=1);

namespace Fluxx\Command;

use Fluxx\Operations\StaleWorkerPruner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'fluxx:runtime:prune-workers', description: 'Prune stopped and stale Fluxx runtime worker states.')]
final class PruneWorkersCommand extends Command
{
    public function __construct(
        private readonly StaleWorkerPruner $pruner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('stopped-retention', null, InputOption::VALUE_REQUIRED, 'Delete rows marked stopped older than this many seconds.', 0);
        $this->addOption('stale-heartbeat', null, InputOption::VALUE_REQUIRED, 'Mark processing/idle rows with no heartbeat for this many seconds as stopped.', 120);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $result = $this->pruner->prune(
                stoppedRetentionSeconds: (int) $input->getOption('stopped-retention'),
                staleHeartbeatSeconds: (int) $input->getOption('stale-heartbeat'),
            );
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Pruned %d stopped worker(s) (deleted), %d stale ghost(s) marked as stopped.',
            $result['deletedStopped'],
            $result['markedStaleAsStopped'],
        ));

        return Command::SUCCESS;
    }
}
