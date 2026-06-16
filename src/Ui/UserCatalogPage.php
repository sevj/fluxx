<?php

declare(strict_types=1);

namespace Fluxx\Ui;

use Fluxx\Entity\User;

final readonly class UserCatalogPage
{
    /**
     * @param list<User> $items
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
     * @return list<User>
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
