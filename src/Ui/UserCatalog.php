<?php

declare(strict_types=1);

namespace Fluxx\Ui;

use Fluxx\Repository\UserRepository;

final readonly class UserCatalog
{
    private const DEFAULT_PER_PAGE = 10;

    public function __construct(
        private UserRepository $userRepository,
    ) {
    }

    public function paginate(int $page = 1, int $perPage = self::DEFAULT_PER_PAGE, string $searchQuery = ''): UserCatalogPage
    {
        $page = max($page, 1);
        $perPage = max($perPage, 1);
        $searchQuery = trim($searchQuery);
        $totalItems = $this->userRepository->countBySearch($searchQuery);
        $totalPages = max((int) ceil($totalItems / $perPage), 1);
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        return new UserCatalogPage(
            items: $this->userRepository->findPaginatedBySearch($searchQuery, $perPage, $offset),
            currentPage: $page,
            perPage: $perPage,
            totalItems: $totalItems,
            totalPages: $totalPages,
        );
    }
}
