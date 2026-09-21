<?php

declare(strict_types=1);

namespace Fluxx\Command;

use DateTimeImmutable;
use Fluxx\Entity\Enum\WorkflowRunStatus;
use Fluxx\Repository\WorkflowRunRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'fluxx:prune:runs', description: 'Delete completed or terminal workflow runs older than the given threshold.')]
final class PruneRunsCommand extends Command
{
    private const DEFAULT_RETENTION_DAYS = 30;
    private const TERMINAL_STATUSES = ['completed', 'failed', 'partially_failed', 'cancelled', 'payloads_pruned'];

    public function __construct(
        private readonly WorkflowRunRepository $runRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('older-than', null, InputOption::VALUE_REQUIRED, 'Delete runs created before this many days ago.', self::DEFAULT_RETENTION_DAYS)
            ->addOption('before', null, InputOption::VALUE_REQUIRED, 'Delete runs created before this date (Y-m-d). Overrides --older-than.')
            ->addOption('status', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Restrict to these terminal statuses (defaults to completed, failed, partially_failed, cancelled).', [])
            ->addOption('workflow', null, InputOption::VALUE_REQUIRED, 'Restrict pruning to the given workflow code.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report the count that would be deleted without actually deleting.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $before = $this->resolveThreshold($input);

        if ($before === null) {
            $io->error('Invalid --before date. Expected format Y-m-d.');

            return Command::FAILURE;
        }

        $statuses = $this->normalizeStatuses($input, $io);

        if ($statuses === null) {
            return Command::FAILURE;
        }

        $workflowCode = $input->getOption('workflow');
        $isDryRun = (bool) $input->getOption('dry-run');

        $count = $this->runRepository->countRunsBeforeDate($before, $statuses, $workflowCode);

        if ($isDryRun) {
            $io->note(sprintf(
                'Dry run: %d run(s)%s older than %s would be deleted.',
                $count,
                $statuses !== [] ? ' with status in [' . implode(', ', $statuses) . ']' : '',
                $before->format('Y-m-d'),
            ));

            return Command::SUCCESS;
        }

        $deleted = $this->runRepository->deleteRunsBeforeDate($before, $statuses, $workflowCode);

        $io->success(sprintf(
            'Deleted %d run(s)%s older than %s%s.',
            $deleted,
            $statuses !== [] ? ' with status in [' . implode(', ', $statuses) . ']' : '',
            $before->format('Y-m-d'),
            $workflowCode !== null ? ' for workflow "' . $workflowCode . '"' : '',
        ));

        return Command::SUCCESS;
    }

    private function resolveThreshold(InputInterface $input): ?DateTimeImmutable
    {
        $beforeOption = $input->getOption('before');

        if (is_string($beforeOption) && $beforeOption !== '') {
            $date = DateTimeImmutable::createFromFormat('Y-m-d', $beforeOption);

            return $date instanceof DateTimeImmutable ? $date->setTime(0, 0, 0) : null;
        }

        $days = max((int) $input->getOption('older-than'), 0);

        return new DateTimeImmutable('-' . $days . ' days');
    }

    /**
     * @return list<string>|null
     */
    private function normalizeStatuses(InputInterface $input, SymfonyStyle $io): ?array
    {
        $rawStatuses = $input->getOption('status');

        if ($rawStatuses === []) {
            return self::TERMINAL_STATUSES;
        }

        $normalized = [];

        foreach ($rawStatuses as $rawStatus) {
            $status = WorkflowRunStatus::tryFrom($rawStatus);

            if ($status === null) {
                $io->error(sprintf('Unknown status "%s". Valid statuses: %s.', $rawStatus, implode(', ', array_column(WorkflowRunStatus::cases(), 'value'))));

                return null;
            }

            $normalized[] = $status->value;
        }

        return $normalized;
    }
}
