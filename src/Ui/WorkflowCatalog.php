<?php

declare(strict_types=1);

namespace Fluxx\Ui;

use Fluxx\Repository\WorkflowRunRepository;
use Fluxx\Workflow\SynchronizationRegistry;

final readonly class WorkflowCatalog
{
    private const DEFAULT_PER_PAGE = 10;

    public function __construct(
        private SynchronizationRegistry $registry,
        private WorkflowRunRepository $workflowRunRepository,
    ) {
    }

    /**
     * @return list<WorkflowOverview>
     */
    public function all(string $searchQuery = ''): array
    {
        $overviews = [];
        $searchQuery = trim($searchQuery);

        foreach ($this->registry->all() as $workflow) {
            $definition = $workflow->definition();
            $latestRun = $this->workflowRunRepository->findLatestOneByWorkflowName($definition->code());

            $overview = new WorkflowOverview(
                code: $definition->code(),
                name: $definition->name(),
                sourceSystem: $definition->sourceSystem(),
                targetSystem: $definition->targetSystem(),
                lastExecutionAt: $latestRun?->createdAt(),
                executionCount: $this->workflowRunRepository->countByWorkflowName($definition->code()),
                errorCount: $this->workflowRunRepository->countErroredByWorkflowName($definition->code()),
                lastErrorAt: $this->workflowRunRepository->findLatestErrorAtByWorkflowName($definition->code()),
            );

            if ($searchQuery !== '' && !$this->matchesSearch($overview, $searchQuery)) {
                continue;
            }

            $overviews[] = $overview;
        }

        usort(
            $overviews,
            static fn (WorkflowOverview $left, WorkflowOverview $right): int => $left->name() <=> $right->name(),
        );

        return $overviews;
    }

    public function paginate(int $page = 1, int $perPage = self::DEFAULT_PER_PAGE, string $searchQuery = ''): WorkflowCatalogPage
    {
        $page = max($page, 1);
        $perPage = max($perPage, 1);
        $items = $this->all($searchQuery);
        $totalItems = count($items);
        $totalPages = max((int) ceil($totalItems / $perPage), 1);
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        return new WorkflowCatalogPage(
            items: array_values(array_slice($items, $offset, $perPage)),
            currentPage: $page,
            perPage: $perPage,
            totalItems: $totalItems,
            totalPages: $totalPages,
        );
    }

    private function matchesSearch(WorkflowOverview $overview, string $searchQuery): bool
    {
        $needle = mb_strtolower($searchQuery);

        foreach ([
            $overview->name(),
            $overview->code(),
            $overview->sourceSystem(),
            $overview->targetSystem(),
        ] as $haystack) {
            if (str_contains(mb_strtolower($haystack), $needle)) {
                return true;
            }
        }

        return false;
    }
}
