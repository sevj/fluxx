<?php

declare(strict_types=1);

namespace Fluxx\Command;

use DateTimeImmutable;
use Exception;
use Fluxx\Observer\ObserverClient;
use Fluxx\Observer\ObserverHealthPusher;
use Fluxx\Observer\ObserverPushException;
use Fluxx\Observer\ObserverRunPusher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'fluxx:observer:push', description: 'Push the system health and the recent run reports to the Fluxx Observer.')]
final class PushToObserverCommand extends Command
{
    public function __construct(
        private readonly ObserverClient $observerClient,
        private readonly ObserverHealthPusher $healthPusher,
        private readonly ObserverRunPusher $runPusher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('since', null, InputOption::VALUE_REQUIRED, 'Resend the runs changed since this date or relative time (e.g. "-2 days") instead of since the previous push.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->observerClient->isConfigured()) {
            $io->note('The observer push is disabled: set fluxx.observer.url and fluxx.observer.token.');

            return Command::SUCCESS;
        }

        $since = null;

        if ($input->getOption('since') !== null) {
            try {
                $since = new DateTimeImmutable((string) $input->getOption('since'));
            } catch (Exception) {
                $io->error(sprintf('Invalid --since value "%s".', $input->getOption('since')));

                return Command::INVALID;
            }
        }

        $healthPushed = true;

        try {
            $this->healthPusher->push();
        } catch (ObserverPushException $exception) {
            $healthPushed = false;
            $io->warning('System health not pushed. ' . $exception->getMessage());
        }

        try {
            $report = $this->runPusher->push($since);
        } catch (ObserverPushException $exception) {
            $io->error('Run reports not pushed. ' . $exception->getMessage());

            return Command::FAILURE;
        }

        foreach ($report->rejectedRuns as $rejectedRun) {
            $io->warning(sprintf('Run %s refused by the observer: %s', $rejectedRun['runId'], $rejectedRun['error']));
        }

        $io->success(sprintf(
            '%d run report(s) changed since %s pushed to the observer%s.',
            $report->pushedRunCount,
            $report->since->format('Y-m-d H:i:s'),
            $healthPushed ? ', with the system health' : '',
        ));

        return $healthPushed && $report->rejectedRuns === [] ? Command::SUCCESS : Command::FAILURE;
    }
}
