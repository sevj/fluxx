<?php

declare(strict_types=1);

namespace Fluxx\Ui;

use Fluxx\Repository\WorkflowRunRepository;
use Fluxx\Workflow\SynchronizationRegistry;

final readonly class WorkflowCatalog
{
    private const DEFAULT_PER_PAGE = 50;

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
        $definitions = $this->filteredDefinitions($searchQuery);
        $statsByWorkflowCode = $this->workflowRunRepository->summarizeByWorkflowNames(array_map(
            static fn (\Fluxx\Workflow\WorkflowDefinition $definition): string => $definition->code(),
            $definitions,
        ));
        $overviews = [];

        foreach ($definitions as $definition) {
            $overviews[] = $this->createOverview(
                $definition->code(),
                $definition->name(),
                $definition->sourceSystem(),
                $definition->targetSystem(),
                $statsByWorkflowCode[$definition->code()] ?? null,
                $definition->description(),
                $definition->category(),
            );
        }

        return $overviews;
    }

    public function paginate(int $page = 1, int $perPage = self::DEFAULT_PER_PAGE, string $searchQuery = ''): WorkflowCatalogPage
    {
        $page = max($page, 1);
        $perPage = max($perPage, 1);
        $definitions = $this->filteredDefinitions($searchQuery);
        $totalItems = count($definitions);
        $totalPages = max((int) ceil($totalItems / $perPage), 1);
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;
        $pageDefinitions = array_slice($definitions, $offset, $perPage);
        $statsByWorkflowCode = $this->workflowRunRepository->summarizeByWorkflowNames(array_map(
            static fn (\Fluxx\Workflow\WorkflowDefinition $definition): string => $definition->code(),
            $pageDefinitions,
        ));
        $items = array_map(
            fn (\Fluxx\Workflow\WorkflowDefinition $definition): WorkflowOverview => $this->createOverview(
                $definition->code(),
                $definition->name(),
                $definition->sourceSystem(),
                $definition->targetSystem(),
                $statsByWorkflowCode[$definition->code()] ?? null,
                $definition->description(),
                $definition->category(),
            ),
            $pageDefinitions,
        );

        return new WorkflowCatalogPage(
            items: array_values($items),
            currentPage: $page,
            perPage: $perPage,
            totalItems: $totalItems,
            totalPages: $totalPages,
        );
    }

    /**
     * @return list<\Fluxx\Workflow\WorkflowDefinition>
     */
    private function filteredDefinitions(string $searchQuery): array
    {
        $searchQuery = trim($searchQuery);
        $definitions = [];

        foreach ($this->registry->all() as $workflow) {
            $definition = $workflow->definition();

            if ($searchQuery !== '' && !$this->matchesSearch(
                $definition->name(),
                $definition->code(),
                $definition->sourceSystem(),
                $definition->targetSystem(),
                $definition->description(),
                $definition->category(),
                $searchQuery,
            )) {
                continue;
            }

            $definitions[] = $definition;
        }

        usort(
            $definitions,
            static function (\Fluxx\Workflow\WorkflowDefinition $left, \Fluxx\Workflow\WorkflowDefinition $right): int {
                $leftCategory = $left->category();
                $rightCategory = $right->category();

                if ($leftCategory === null && $rightCategory !== null) {
                    return 1;
                }

                if ($leftCategory !== null && $rightCategory === null) {
                    return -1;
                }

                if ($leftCategory !== null && $rightCategory !== null) {
                    $categoryCmp = mb_strtolower($leftCategory) <=> mb_strtolower($rightCategory);

                    if ($categoryCmp !== 0) {
                        return $categoryCmp;
                    }
                }

                return $left->name() <=> $right->name();
            },
        );

        return $definitions;
    }

    /**
     * @param array{
     *     executionCount: int,
     *     errorCount: int,
     *     lastExecutionAt: ?\DateTimeImmutable,
     *     lastErrorAt: ?\DateTimeImmutable
     * }|null $stats
     */
    private function createOverview(
        string $code,
        string $name,
        string $sourceSystem,
        string $targetSystem,
        ?array $stats,
        ?string $description = null,
        ?string $category = null,
    ): WorkflowOverview {
        return new WorkflowOverview(
            code: $code,
            name: $name,
            sourceSystem: $sourceSystem,
            targetSystem: $targetSystem,
            lastExecutionAt: $stats['lastExecutionAt'] ?? null,
            executionCount: $stats['executionCount'] ?? 0,
            errorCount: $stats['errorCount'] ?? 0,
            lastErrorAt: $stats['lastErrorAt'] ?? null,
            description: $description,
            category: $category,
        );
    }

    private function matchesSearch(
        string $name,
        string $code,
        string $sourceSystem,
        string $targetSystem,
        ?string $description,
        ?string $category,
        string $searchQuery,
    ): bool {
        $needle = mb_strtolower($searchQuery);

        foreach ([$name, $code, $sourceSystem, $targetSystem, $description, $category] as $haystack) {
            if ($haystack !== null && str_contains(mb_strtolower($haystack), $needle)) {
                return true;
            }
        }

        return false;
    }
}
