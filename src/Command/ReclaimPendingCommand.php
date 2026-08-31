<?php

declare(strict_types=1);

namespace Fluxx\Command;

use Fluxx\Operations\PendingMessageReclaimer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'fluxx:runtime:reclaim-pending', description: 'Reclaim idle pending messages and hand them to a live consumer for immediate retry.')]
final class ReclaimPendingCommand extends Command
{
    public function __construct(
        private readonly PendingMessageReclaimer $reclaimer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('min-idle', null, InputOption::VALUE_REQUIRED, 'Reclaim pending entries idle for at least this many seconds.', 60);
        $this->addOption('count', null, InputOption::VALUE_REQUIRED, 'Maximum number of pending entries to reclaim.', 100);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $result = $this->reclaimer->reclaim(
                minIdleSeconds: (int) $input->getOption('min-idle'),
                count: (int) $input->getOption('count'),
            );
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        if ($result['claimed'] === 0) {
            $io->text('No pending message was eligible for reclaim.');
        } else {
            $io->success(sprintf(
                'Reclaimed %d pending message(s) onto consumer "%s" (min idle %ds).',
                $result['claimed'],
                $result['targetConsumer'],
                $result['minIdleSeconds'],
            ));
        }

        return Command::SUCCESS;
    }
}
