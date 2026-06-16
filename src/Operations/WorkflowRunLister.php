<?php

declare(strict_types=1);

namespace Fluxx\Operations;

use Fluxx\Repository\WorkflowRunRepository;
use Fluxx\Ui\WorkflowRunFilters;

class WorkflowRunLister
{
    public function __construct(
        private readonly WorkflowRunRepository $workflowRunRepository,
    ) {
    }

    public function list(WorkflowRunFilters $filters, int $page = 1, int $perPage = 20): WorkflowRunListing
    {
        $page = max($page, 1);
        $perPage = max($perPage, 1);
        $totalItems = $this->workflowRunRepository->countByFilters($filters);
        $totalPages = max((int) ceil($totalItems / $perPage), 1);
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $items = array_map(
            static fn ($run): WorkflowRunListItem => new WorkflowRunListItem(
                runId: $run->runId(),
                workflowCode: $run->workflowName(),
                trigger: $run->trigger(),
                status: $run->status()->value,
                sourceSystem: $run->sourceSystem(),
                targetSystem: $run->targetSystem(),
                createdAt: $run->createdAt(),
                errorMessage: $run->errorMessage(),
            ),
            $this->workflowRunRepository->findPaginatedByFilters($filters, $perPage, $offset),
        );

        return new WorkflowRunListing(
            items: $items,
            currentPage: $page,
            perPage: $perPage,
            totalItems: $totalItems,
            totalPages: $totalPages,
        );
    }
}
