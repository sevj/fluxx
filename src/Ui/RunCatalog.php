<?php

declare(strict_types=1);

namespace Fluxx\Ui;

use Fluxx\Entity\WorkflowRun;
use Fluxx\Repository\WorkflowRunRepository;
use Fluxx\Workflow\SynchronizationRegistry;
use Fluxx\Workflow\WorkflowInterface;

final readonly class RunCatalog
{
    private const DEFAULT_PER_PAGE = 20;

    public function __construct(
        private WorkflowRunRepository $workflowRunRepository,
        private SynchronizationRegistry $registry,
    ) {
    }

    public function paginate(int $page, int $perPage, WorkflowRunFilters $filters): RunCatalogPage
    {
        $page = max($page, 1);
        $perPage = max($perPage, 1);
        $totalItems = $this->workflowRunRepository->countByFilters($filters);
        $totalPages = max((int) ceil($totalItems / $perPage), 1);
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;
        $runs = $this->workflowRunRepository->findPaginatedByFilters($filters, $perPage, $offset);

        $items = array_map(
            fn (WorkflowRun $run): RunRowView => $this->createRowView($run),
            $runs,
        );

        return new RunCatalogPage(
            items: array_values($items),
            currentPage: $page,
            perPage: $perPage,
            totalItems: $totalItems,
            totalPages: $totalPages,
        );
    }

    /**
     * @return list<WorkflowChoiceView>
     */
    public function availableWorkflows(): array
    {
        $choices = array_map(
            static fn (WorkflowInterface $workflow): WorkflowChoiceView => new WorkflowChoiceView(
                $workflow->definition()->code(),
                $workflow->definition()->name(),
            ),
            $this->registry->all(),
        );

        usort($choices, static fn (WorkflowChoiceView $a, WorkflowChoiceView $b): int => strcmp($a->name(), $b->name()));

        return $choices;
    }

    private function createRowView(WorkflowRun $run): RunRowView
    {
        $workflowCode = $run->workflowName();
        $definition = $this->registry->has($workflowCode)
            ? $this->registry->get($workflowCode)->definition()
            : null;
        $workflowName = $definition?->name();
        $workflowCategory = $definition?->category();

        return new RunRowView(
            runId: $run->runId(),
            workflowCode: $workflowCode,
            workflowName: $workflowName,
            workflowCategory: $workflowCategory,
            sourceSystem: $run->sourceSystem(),
            targetSystem: $run->targetSystem(),
            trigger: $run->trigger(),
            status: $run->status()->value,
            lockKey: $run->lockKey(),
            lockScope: $run->lockScope()?->value,
            createdAt: $run->createdAt(),
            startedAt: $run->startedAt(),
            finishedAt: $run->finishedAt(),
            errorMessage: $run->errorMessage(),
        );
    }
}
