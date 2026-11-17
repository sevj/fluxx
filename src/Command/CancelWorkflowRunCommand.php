<?php

declare(strict_types=1);

namespace Fluxx\Command;

use Fluxx\Operations\WorkflowCancelOperator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'fluxx:run:cancel', description: 'Cancel an active workflow run, as the cancel button of the run page does.')]
final class CancelWorkflowRunCommand extends Command
{
    public function __construct(
        private readonly WorkflowCancelOperator $workflowCancelOperator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('run-id', InputArgument::REQUIRED, 'Workflow run id.')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Optional operator reason.')
            ->addOption('operator', null, InputOption::VALUE_REQUIRED, 'Optional operator user identifier.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $runId = (string) $input->getArgument('run-id');
        $reason = $input->getOption('reason');
        $operator = $input->getOption('operator');

        try {
            $cancelled = $this->workflowCancelOperator->cancelRun(
                runId: $runId,
                trigger: 'cli',
                reason: is_string($reason) && $reason !== '' ? $reason : null,
                operatorUser: is_string($operator) && $operator !== '' ? $operator : null,
            );
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        if (!$cancelled) {
            $io->error(sprintf('Workflow run "%s" is no longer active and cannot be cancelled.', $runId));

            return Command::FAILURE;
        }

        $io->success(sprintf('Workflow run "%s" cancelled.', $runId));

        return Command::SUCCESS;
    }
}
