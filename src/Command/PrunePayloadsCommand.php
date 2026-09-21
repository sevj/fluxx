<?php

declare(strict_types=1);

namespace Fluxx\Command;

use DateTimeImmutable;
use Fluxx\Entity\Enum\WorkflowRunStatus;
use Fluxx\Repository\WorkflowPayloadRepository;
use Fluxx\Repository\WorkflowRunRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'fluxx:prune:payloads', description: 'Delete payload snapshots from terminal runs older than the given threshold, marking those runs so they cannot be relaunched.')]
final class PrunePayloadsCommand extends Command
{
    private const DEFAULT_RETENTION_DAYS = 30;

    private const TERMINAL_STATUSES = [
        WorkflowRunStatus::Completed,
        WorkflowRunStatus::Failed,
        WorkflowRunStatus::PartiallyFailed,
        WorkflowRunStatus::Cancelled,
    ];

    public function __construct(
        private readonly WorkflowPayloadRepository $payloadRepository,
        private readonly WorkflowRunRepository $runRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('older-than', null, InputOption::VALUE_REQUIRED, 'Delete payloads created before this many days ago.', self::DEFAULT_RETENTION_DAYS)
            ->addOption('before', null, InputOption::VALUE_REQUIRED, 'Delete payloads created before this date (Y-m-d). Overrides --older-than.')
            ->addOption('workflow', null, InputOption::VALUE_REQUIRED, 'Restrict pruning to the given workflow code.')
            ->addOption('status', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Restrict to these terminal statuses (defaults to completed, failed, partially_failed, cancelled).', [])
            ->addOption('no-mark-runs', null, InputOption::VALUE_NONE, 'Skip marking runs as payloads_pruned. Payloads are deleted but runs keep their original status.')
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
        $markRuns = !$input->getOption('no-mark-runs');
        $statusValues = array_map(static fn (WorkflowRunStatus $status): string => $status->value, $statuses);
        $scope = $workflowCode !== null ? ' for workflow "' . $workflowCode . '"' : '';

        $markedRuns = 0;

        if ($markRuns) {
            $terminalRunCount = $this->runRepository->countRunsBeforeDate($before, $statusValues, $workflowCode);

            if ($isDryRun) {
                $io->note(sprintf(
                    'Dry run: %d terminal run(s)%s would be marked as payloads_pruned.',
                    $terminalRunCount,
                    $scope,
                ));

                $payloadCount = $this->payloadRepository->countBeforeDate($before, $workflowCode);

                $io->note(sprintf(
                    'Dry run: %d payload(s) older than %s%s would be deleted.',
                    $payloadCount,
                    $before->format('Y-m-d'),
                    $scope,
                ));

                return Command::SUCCESS;
            }

            $markedRuns = $this->runRepository->markRunsAsPayloadsPruned($before, $statusValues, $workflowCode);

            $deleted = $this->payloadRepository->deletePayloadsOfPrunedRunsBeforeDate($before, $workflowCode);

            $io->success(sprintf(
                'Marked %d run(s) as payloads_pruned and deleted %d payload(s) older than %s%s.',
                $markedRuns,
                $deleted,
                $before->format('Y-m-d'),
                $scope,
            ));

            return Command::SUCCESS;
        }

        $payloadCount = $this->payloadRepository->countBeforeDate($before, $workflowCode);

        if ($isDryRun) {
            $io->note(sprintf(
                'Dry run: %d payload(s) older than %s%s would be deleted (runs keep their original status).',
                $payloadCount,
                $before->format('Y-m-d'),
                $scope,
            ));

            return Command::SUCCESS;
        }

        $deleted = $this->payloadRepository->deleteBeforeDate($before, $workflowCode);

        $io->warning(sprintf(
            'Deleted %d payload(s) older than %s%s without marking runs. Affected runs can no longer be relaunched and will report missing payloads on demand.',
            $deleted,
            $before->format('Y-m-d'),
            $scope,
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
     * @return list<WorkflowRunStatus>|null
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

            $normalized[] = $status;
        }

        return $normalized;
    }
}
