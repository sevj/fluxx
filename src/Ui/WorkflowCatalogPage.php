<?php

declare(strict_types=1);

namespace Fluxx\Ui;

final readonly class WorkflowCatalogPage
{
    /**
     * @param list<WorkflowOverview> $items
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
     * @return list<WorkflowOverview>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * Workflows grouped by category, preserving the page ordering.
     *
     * Named categories are returned first (sorted alphabetically, case-insensitive).
     * Workflows without a category are gathered into a single trailing group
     * whose {@see WorkflowCatalogGroup::category()} is null, rendered as
     * "Uncategorized" by the UI.
     *
     * @return list<WorkflowCatalogGroup>
     */
    public function groups(): array
    {
        $byCategory = [];

        foreach ($this->items as $overview) {
            $category = $overview->category();
            $key = $category ?? '';
            $byCategory[$key][] = $overview;
        }

        $namedKeys = array_filter(array_keys($byCategory), static fn (string $key): bool => $key !== '');
        usort($namedKeys, static fn (string $left, string $right): int => mb_strtolower($left) <=> mb_strtolower($right));

        $groups = [];

        foreach ($namedKeys as $key) {
            $groups[] = new WorkflowCatalogGroup($key, $byCategory[$key]);
        }

        if (isset($byCategory[''])) {
            $groups[] = new WorkflowCatalogGroup(null, $byCategory['']);
        }

        return $groups;
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

    public function hasPreviousPage(): bool
    {
        return $this->currentPage > 1;
    }

    public function hasNextPage(): bool
    {
        return $this->currentPage < $this->totalPages;
    }

    public function previousPage(): int
    {
        return max($this->currentPage - 1, 1);
    }

    public function nextPage(): int
    {
        return min($this->currentPage + 1, $this->totalPages);
    }

    public function firstItemNumber(): int
    {
        if ($this->totalItems === 0) {
            return 0;
        }

        return (($this->currentPage - 1) * $this->perPage) + 1;
    }

    public function lastItemNumber(): int
    {
        if ($this->totalItems === 0) {
            return 0;
        }

        return min($this->currentPage * $this->perPage, $this->totalItems);
    }

    /**
     * @return list<int>
     */
    public function pages(): array
    {
        if ($this->totalPages <= 1) {
            return [1];
        }

        return range(1, $this->totalPages);
    }
}
