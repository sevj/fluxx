<?php

declare(strict_types=1);

namespace Fluxx\Command;

use Fluxx\Repository\WorkflowRunLookupInterface;
use Fluxx\Repository\WorkflowRunReportRepository;
use Fluxx\Workflow\Report\RunNotReportableException;
use Fluxx\Workflow\Report\RunReportBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'fluxx:run:report', description: 'Build the report of a completed run again, replacing the stored one.')]
final class BuildRunReportCommand extends Command
{
    public function __construct(
        private readonly WorkflowRunLookupInterface $workflowRunRepository,
        private readonly RunReportBuilder $builder,
        private readonly WorkflowRunReportRepository $reportRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('run-id', InputArgument::REQUIRED, 'The run whose report is built.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $runId = (string) $input->getArgument('run-id');
        $run = $this->workflowRunRepository->findOneByRunId($runId);

        if ($run === null) {
            $io->error(sprintf('Run "%s" was not found.', $runId));

            return Command::FAILURE;
        }

        if (!RunReportBuilder::isReportable($run)) {
            $io->error(sprintf('Run "%s" has the status "%s": a report is only built for a completed run.', $runId, $run->status()->value));

            return Command::FAILURE;
        }

        try {
            $content = $this->builder->build($run);
        } catch (RunNotReportableException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $this->reportRepository->save($run, RunReportBuilder::FORMAT_VERSION, $content);
        $io->success(sprintf('Built the report of run "%s".', $runId));

        return Command::SUCCESS;
    }
}
