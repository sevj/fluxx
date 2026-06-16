<?php

declare(strict_types=1);

namespace Fluxx\Command;

use Fluxx\Operations\WorkflowRetryOperator;
use Fluxx\Workflow\Lock\WorkflowExecutionLockConflict;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'fluxx:run:retry', description: 'Retry a workflow run by creating a relaunch run.')]
final class RetryWorkflowRunCommand extends Command
{
    public function __construct(
        private readonly WorkflowRetryOperator $workflowRetryOperator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('run-id', InputArgument::REQUIRED, 'Workflow run id.')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Optional operator reason.')
            ->addOption('operator', null, InputOption::VALUE_REQUIRED, 'Optional operator user identifier.')
            ->addOption('trigger', null, InputOption::VALUE_REQUIRED, 'Retry trigger source.', 'cli');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $reason = $input->getOption('reason');
        $operator = $input->getOption('operator');

        try {
            $newRunId = $this->workflowRetryOperator->retryRun(
                runId: (string) $input->getArgument('run-id'),
                trigger: (string) $input->getOption('trigger'),
                reason: is_string($reason) && $reason !== '' ? $reason : null,
                operatorUser: is_string($operator) && $operator !== '' ? $operator : null,
            );
        } catch (WorkflowExecutionLockConflict $exception) {
            $io->error(sprintf(
                'Workflow "%s" is locked by run "%s" for key "%s".',
                $exception->workflowCode(),
                $exception->activeRunId(),
                $exception->lockKey(),
            ));

            return Command::FAILURE;
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Workflow run retried. New run ID: %s', $newRunId));

        return Command::SUCCESS;
    }
}
