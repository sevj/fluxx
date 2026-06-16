<?php

declare(strict_types=1);

namespace Fluxx\Operations;

final readonly class WorkflowRunListing
{
    /**
     * @param list<WorkflowRunListItem> $items
     */
    public function __construct(
        private array $items,
        private int $currentPage,
        private int $perPage,
        private int $totalItems,
        private int $totalPages,
    ) {
    }

    /**
     * @return list<WorkflowRunListItem>
     */
    public function items(): array
    {
        return $this->items;
    }

    public function currentPage(): int
    {
        return $this->currentPage;
    }

    public function perPage(): int
    {
        return $this->perPage;
    }

    public function totalItems(): int
    {
        return $this->totalItems;
    }

    public function totalPages(): int
    {
        return $this->totalPages;
    }
}
