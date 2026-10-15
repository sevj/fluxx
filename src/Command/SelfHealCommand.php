<?php

declare(strict_types=1);

namespace Fluxx\Command;

use Fluxx\Operations\DeadConsumerPurger;
use Fluxx\Operations\PendingMessageReclaimer;
use Fluxx\Operations\StaleLockReleaser;
use Fluxx\Operations\OrphanRunFinisher;
use Fluxx\Operations\StaleWorkerPruner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'fluxx:runtime:self-heal', description: 'Run all Fluxx runtime self-healing steps (prune workers, release stale locks, purge dead consumers, reclaim pending, finish orphan runs).')]
final class SelfHealCommand extends Command
{
    public function __construct(
        private readonly StaleWorkerPruner $workerPruner,
        private readonly DeadConsumerPurger $consumerPurger,
        private readonly PendingMessageReclaimer $messageReclaimer,
        private readonly StaleLockReleaser $lockReleaser,
        private readonly OrphanRunFinisher $orphanRunFinisher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('stopped-retention', null, InputOption::VALUE_REQUIRED, 'Delete rows marked stopped older than this many seconds.', 0);
        $this->addOption('stale-heartbeat', null, InputOption::VALUE_REQUIRED, 'Mark rows with no heartbeat for this many seconds as stopped.', 120);
        $this->addOption('min-idle', null, InputOption::VALUE_REQUIRED, 'Dead consumer / reclaim threshold in seconds.', 60);
        $this->addOption('count', null, InputOption::VALUE_REQUIRED, 'Maximum pending entries to reclaim.', 100);
        $this->addOption('orphan-threshold', null, InputOption::VALUE_REQUIRED, 'Finish runs with no worker activity for this many seconds. 0 uses the stale lock timeout setting.', 0);
        $this->addOption('skip-reclaim', null, InputOption::VALUE_NONE, 'Do not reclaim pending messages.');
        $this->addOption('skip-locks', null, InputOption::VALUE_NONE, 'Do not release stale locks.');
        $this->addOption('skip-orphans', null, InputOption::VALUE_NONE, 'Do not finish orphaned runs.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $minIdle = (int) $input->getOption('min-idle');
        $failures = 0;

        $io->section('Pruning stale worker states');
        try {
            $prune = $this->workerPruner->prune(
                stoppedRetentionSeconds: (int) $input->getOption('stopped-retention'),
                staleHeartbeatSeconds: (int) $input->getOption('stale-heartbeat'),
            );
            $io->text(sprintf('%d stopped deleted, %d stale ghosts marked stopped.', $prune['deletedStopped'], $prune['markedStaleAsStopped']));
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());
            ++$failures;
        }

        $io->section('Purging dead Redis consumers');
        try {
            $purge = $this->consumerPurger->purge(minIdleSeconds: $minIdle);
            $io->text(sprintf('%d dead consumers purged, %d live consumers skipped.', count($purge['purged']), $purge['skipped']));
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());
            ++$failures;
        }

        if (!$input->getOption('skip-reclaim')) {
            $io->section('Reclaiming pending messages');
            try {
                $reclaim = $this->messageReclaimer->reclaim(minIdleSeconds: $minIdle, count: (int) $input->getOption('count'));
                $io->text(sprintf('%d pending message(s) reclaimed onto "%s".', $reclaim['claimed'], $reclaim['targetConsumer']));
            } catch (\Throwable $exception) {
                $io->warning($exception->getMessage());
            }
        }

        if (!$input->getOption('skip-locks')) {
            $io->section('Releasing stale locks');
            try {
                $locks = $this->lockReleaser->releaseStale(staleHeartbeatSeconds: (int) $input->getOption('stale-heartbeat'));
                $io->text(sprintf('%d stale lock(s) released.', count($locks)));
            } catch (\Throwable $exception) {
                $io->error($exception->getMessage());
                ++$failures;
            }
        }

        if (!$input->getOption('skip-orphans')) {
            $io->section('Finishing orphaned runs');
            try {
                $orphanThreshold = (int) $input->getOption('orphan-threshold');
                $finished = $this->orphanRunFinisher->finishOrphanRuns($orphanThreshold > 0 ? $orphanThreshold : null);
                $io->text(sprintf('%d orphaned run(s) finalized as failed.', count($finished)));
            } catch (\Throwable $exception) {
                $io->error($exception->getMessage());
                ++$failures;
            }
        }

        return $failures === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
