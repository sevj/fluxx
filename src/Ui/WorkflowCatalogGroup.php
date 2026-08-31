<?php

declare(strict_types=1);

namespace Fluxx\Ui;

/**
 * A category bucket used to group workflows on the catalog list.
 *
 * A null category gathers uncategorized workflows (rendered with a dedicated
 * "Uncategorized" label in the UI).
 */
final readonly class WorkflowCatalogGroup
{
    /**
     * @param list<WorkflowOverview> $items
     */
    public function __construct(
        private ?string $category,
        private array $items,
    ) {
    }

    public function category(): ?string
    {
        return $this->category;
    }

    /**
     * @return list<WorkflowOverview>
     */
    public function items(): array
    {
        return $this->items;
    }
}
