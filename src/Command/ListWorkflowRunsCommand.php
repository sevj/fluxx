<?php

declare(strict_types=1);

namespace Fluxx\Command;

use Fluxx\Operations\WorkflowRunFilterFactory;
use Fluxx\Operations\WorkflowRunLister;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'fluxx:run:list', description: 'List workflow runs with operational filters.')]
final class ListWorkflowRunsCommand extends Command
{
    public function __construct(
        private readonly WorkflowRunLister $workflowRunLister,
        private readonly WorkflowRunFilterFactory $workflowRunFilterFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('workflow', null, InputOption::VALUE_REQUIRED, 'Filter by workflow code.')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Filter by run status.')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Filter by source system.')
            ->addOption('target', null, InputOption::VALUE_REQUIRED, 'Filter by target system.')
            ->addOption('errors', null, InputOption::VALUE_REQUIRED, 'Filter by error presence: all, with, without.', 'all')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Filter from date (YYYY-MM-DD).')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Filter to date (YYYY-MM-DD).')
            ->addOption('search', null, InputOption::VALUE_REQUIRED, 'Search query across run id, workflow, trigger, systems and errors.')
            ->addOption('page', null, InputOption::VALUE_REQUIRED, 'Page number.', '1')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Results per page.', '20');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $page = $this->positiveInt($input->getOption('page'));
        $limit = $this->positiveInt($input->getOption('limit'));

        if ($page === null || $limit === null) {
            $io->error('The --page and --limit options must be positive integers.');

            return Command::INVALID;
        }

        $filters = $this->workflowRunFilterFactory->fromArray([
            'workflow' => $input->getOption('workflow'),
            'status' => $input->getOption('status'),
            'source' => $input->getOption('source'),
            'target' => $input->getOption('target'),
            'errors' => $input->getOption('errors'),
            'from' => $input->getOption('from'),
            'to' => $input->getOption('to'),
            'q' => $input->getOption('search'),
        ]);

        $listing = $this->workflowRunLister->list($filters, $page, $limit);

        if ($listing->items() === []) {
            $io->warning('No workflow run matched the current filters.');

            return Command::SUCCESS;
        }

        $io->table(
            ['Run ID', 'Workflow', 'Trigger', 'Status', 'Source', 'Target', 'Created', 'Error'],
            array_map(
                static fn ($item): array => [
                    $item->runId(),
                    $item->workflowCode(),
                    $item->trigger(),
                    $item->status(),
                    $item->sourceSystem(),
                    $item->targetSystem(),
                    $item->createdAt()->format('Y-m-d H:i:s'),
                    $item->errorMessage() ?? '-',
                ],
                $listing->items(),
            ),
        );

        $io->text(sprintf(
            'Showing page %d/%d, %d item(s) on this page, %d total.',
            $listing->currentPage(),
            $listing->totalPages(),
            count($listing->items()),
            $listing->totalItems(),
        ));

        return Command::SUCCESS;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (!is_scalar($value) || !is_numeric((string) $value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
