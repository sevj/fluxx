<?php

declare(strict_types=1);

namespace Fluxx\Command;

use Fluxx\Workflow\FluxxEngine;
use Fluxx\Workflow\Lock\WorkflowExecutionLockConflict;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'fluxx:workflow:run', description: 'Dispatch a workflow run asynchronously.')]
final class RunWorkflowCommand extends Command
{
    public function __construct(
        private readonly FluxxEngine $fluxxEngine,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('workflow', InputArgument::REQUIRED, 'Workflow code.')
            ->addOption('trigger', null, InputOption::VALUE_REQUIRED, 'Trigger name.', 'manual')
            ->addOption('batch-id', null, InputOption::VALUE_REQUIRED, 'Optional batch identifier.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $workflowCode = (string) $input->getArgument('workflow');
        $trigger = (string) $input->getOption('trigger');
        $batchId = $input->getOption('batch-id');

        try {
            $runId = $this->fluxxEngine->run(
                workflowCode: $workflowCode,
                trigger: $trigger,
                batchId: is_string($batchId) && $batchId !== '' ? $batchId : null,
            );
        } catch (WorkflowExecutionLockConflict $exception) {
            $io->error(sprintf(
                'Workflow "%s" is locked by run "%s" for key "%s".',
                $exception->workflowCode(),
                $exception->activeRunId(),
                $exception->lockKey(),
            ));

            return Command::FAILURE;
        }

        $io->success(sprintf('Workflow "%s" dispatched. Run ID: %s', $workflowCode, $runId));

        return Command::SUCCESS;
    }
}
