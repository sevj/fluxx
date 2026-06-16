<?php

declare(strict_types=1);

namespace Fluxx\Command;

use Fluxx\Workflow\Lock\WorkflowExecutionLockConflict;
use Fluxx\Workflow\Relaunch\WorkflowRelaunchMode;
use Fluxx\Workflow\Relaunch\WorkflowRelaunchService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'fluxx:workflow:relaunch', description: 'Relaunch a workflow run.')]
final class RelaunchWorkflowCommand extends Command
{
    public function __construct(
        private readonly WorkflowRelaunchService $workflowRelaunchService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('run-id', InputArgument::REQUIRED, 'Original workflow run id.')
            ->addOption('mode', null, InputOption::VALUE_REQUIRED, 'Relaunch mode: full, step or branch.', WorkflowRelaunchMode::Full->value)
            ->addOption('step', null, InputOption::VALUE_REQUIRED, 'Restart step code for step or branch mode.')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Optional relaunch reason.')
            ->addOption('operator', null, InputOption::VALUE_REQUIRED, 'Optional operator user identifier.')
            ->addOption('trigger', null, InputOption::VALUE_REQUIRED, 'Relaunch trigger source.', 'cli');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $mode = WorkflowRelaunchMode::from((string) $input->getOption('mode'));
        } catch (\ValueError $exception) {
            $io->error(sprintf('Unknown relaunch mode "%s".', (string) $input->getOption('mode')));

            return Command::FAILURE;
        }

        $step = $input->getOption('step');
        $reason = $input->getOption('reason');
        $operator = $input->getOption('operator');

        try {
            $newRunId = $this->workflowRelaunchService->relaunch(
                originalRunId: (string) $input->getArgument('run-id'),
                mode: $mode,
                restartStepCode: is_string($step) && $step !== '' ? $step : null,
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

        $io->success(sprintf('Workflow run relaunched. New run ID: %s', $newRunId));

        return Command::SUCCESS;
    }
}
