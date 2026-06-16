<?php

declare(strict_types=1);

namespace Fluxx\Ui;

final readonly class WorkflowStepRowView
{
    /**
     * @param list<WorkflowGraphNodeView> $nodes
     */
    public function __construct(
        private array $nodes,
        private string $layout,
        private bool $connectsToMergeNext = false,
    ) {
    }

    /**
     * @return list<WorkflowGraphNodeView>
     */
    public function nodes(): array
    {
        return $this->nodes;
    }

    public function layout(): string
    {
        return $this->layout;
    }

    public function isBranchSplit(): bool
    {
        return $this->layout === 'split';
    }

    public function isParallel(): bool
    {
        return $this->layout === 'parallel';
    }

    public function isMerge(): bool
    {
        return $this->layout === 'merge';
    }

    public function connectsToMergeNext(): bool
    {
        return $this->connectsToMergeNext;
    }

    public function connectorColumnStart(): int
    {
        return $this->nodes[0]->columnStart();
    }

    public function connectorColumnSpan(): int
    {
        $first = $this->nodes[0];
        $last = $this->nodes[count($this->nodes) - 1];

        return $last->columnEnd() - $first->columnStart() + 1;
    }
}
