<?php

declare(strict_types=1);

namespace Fluxx\Command;

use DateTimeImmutable;
use DateTimeZone;
use Fluxx\Reporting\DailyWorkflowRecapBuilder;
use Fluxx\Reporting\DailyWorkflowRecapMailer;
use Fluxx\Settings\DailyRecapSettings;
use Fluxx\Settings\DailyRecapSettingsManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use function is_string;

#[AsCommand(name: 'fluxx:workflow:daily-recap', description: 'Send a daily workflow execution recap email.')]
final class SendDailyWorkflowRecapCommand extends Command
{
    public function __construct(
        private readonly DailyRecapSettingsManager $settingsManager,
        private readonly DailyWorkflowRecapBuilder $recapBuilder,
        private readonly DailyWorkflowRecapMailer $recapMailer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'Report date in configured timezone (YYYY-MM-DD).')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Report lower bound datetime.')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Report upper bound datetime.')
            ->addOption('recipient', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Override recipients.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the recap without sending email.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $settings = $this->settingsManager->get();
        $recipients = $input->getOption('recipient');

        if ($recipients !== []) {
            $settings = new DailyRecapSettings(
                enabled: true,
                recipients: $recipients,
                sender: $settings->sender(),
                subjectPrefix: $settings->subjectPrefix(),
                timezone: $settings->timezone(),
                sendEmptyReport: true,
            );
        }

        if (!$settings->enabled() && !$input->getOption('dry-run')) {
            $io->warning('Daily recap is disabled in Fluxx settings.');

            return Command::SUCCESS;
        }

        if ($settings->recipients() === [] && !$input->getOption('dry-run')) {
            $io->error('No daily recap recipient configured.');

            return Command::FAILURE;
        }

        [$from, $to] = $this->resolvePeriod($input, $settings);
        $recap = $this->recapBuilder->build($from, $to);

        $io->section(sprintf('Workflow recap from %s to %s', $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')));
        $io->definitionList(
            ['Total runs' => $recap->totalRuns()],
            ['Processed records' => $recap->processedCount()],
            ['Successful records' => $recap->successCount()],
            ['Errored records' => $recap->errorCount()],
        );
        $io->table(['Status', 'Count'], array_map(static fn (string $status, int $count): array => [$status, $count], array_keys($recap->statusCounts()), $recap->statusCounts()));

        foreach ($recap->erroredRuns() as $run) {
            $io->section(sprintf('Errored run %s (%s)', $run['runId'], $run['workflow']));
            $io->text(sprintf('%s · %s', $run['status'], $run['error'] ?? '-'));
            $io->table(
                ['Step', 'Type', 'Status', 'Processed', 'Success', 'Errors', 'Retries', 'Duration', 'Error'],
                array_map(
                    static fn (array $step): array => [
                        $step['code'],
                        $step['type'],
                        $step['status'],
                        $step['processed'],
                        $step['success'],
                        $step['errors'],
                        $step['retries'],
                        $step['durationMs'] === null ? '-' : $step['durationMs'] . ' ms',
                        $step['error'] ?? '-',
                    ],
                    $run['steps'],
                ),
            );
        }

        if ($recap->totalRuns() === 0 && !$settings->sendEmptyReport()) {
            $io->warning('No workflow run found and empty reports are disabled.');

            return Command::SUCCESS;
        }

        if ($input->getOption('dry-run')) {
            return Command::SUCCESS;
        }

        $this->recapMailer->send($recap, $settings);
        $io->success('Daily workflow recap email sent.');

        return Command::SUCCESS;
    }

    /**
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    private function resolvePeriod(InputInterface $input, DailyRecapSettings $settings): array
    {
        $timezone = new DateTimeZone($settings->timezone());
        $from = $input->getOption('from');
        $to = $input->getOption('to');

        if (is_string($from) && $from !== '' && is_string($to) && $to !== '') {
            return [new DateTimeImmutable($from, $timezone), new DateTimeImmutable($to, $timezone)];
        }

        $date = $input->getOption('date');
        $day = is_string($date) && $date !== ''
            ? new DateTimeImmutable($date . ' 00:00:00', $timezone)
            : new DateTimeImmutable('yesterday 00:00:00', $timezone);

        return [$day, $day->modify('+1 day')];
    }
}
