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
use function in_array;
use function is_numeric;
use function is_array;
use function is_string;
use function json_decode;
use function preg_match;
use function sprintf;
use const JSON_THROW_ON_ERROR;

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
            ->addOption('batch-id', null, InputOption::VALUE_REQUIRED, 'Optional batch identifier.')
            ->addOption(
                'parameter',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Workflow parameter as key=value. Repeat the option to pass multiple parameters.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $workflowCode = (string) $input->getArgument('workflow');
        $trigger = (string) $input->getOption('trigger');
        $batchId = $input->getOption('batch-id');
        $parameters = $this->parseParameters($input->getOption('parameter'));

        if ($parameters === null) {
            $io->error('Each --parameter option must use the format key=value.');

            return Command::INVALID;
        }

        try {
            $runId = $this->fluxxEngine->run(
                workflowCode: $workflowCode,
                trigger: $trigger,
                batchId: is_string($batchId) && $batchId !== '' ? $batchId : null,
                metadata: $parameters === [] ? [] : ['parameters' => $parameters],
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

    /**
     * @param array<int, mixed> $rawParameters
     *
     * @return array<string, mixed>|null
     */
    private function parseParameters(array $rawParameters): ?array
    {
        $parameters = [];

        foreach ($rawParameters as $rawParameter) {
            if (!is_string($rawParameter) || !preg_match('/^(?<key>[^=]+)=(?<value>.*)$/', $rawParameter, $matches)) {
                return null;
            }

            $parameters[$matches['key']] = $this->normalizeParameterValue($matches['value']);
        }

        return $parameters;
    }

    private function normalizeParameterValue(string $value): mixed
    {
        if (in_array($value, ['true', 'false'], true)) {
            return $value === 'true';
        }

        if ($value === 'null') {
            return null;
        }

        if (preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        if ($value !== '' && in_array($value[0], ['{', '['], true)) {
            try {
                $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

                if (is_array($decoded)) {
                    return $decoded;
                }
            } catch (\JsonException) {
            }
        }

        return $value;
    }
}
