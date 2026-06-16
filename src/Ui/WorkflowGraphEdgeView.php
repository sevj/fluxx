<?php

declare(strict_types=1);

namespace Fluxx\Ui;

final readonly class WorkflowGraphEdgeView
{
    private const COLUMN_WIDTH = 220;
    private const ROW_HEIGHT = 120;
    private const NODE_LEFT_OFFSET = 20;
    private const NODE_RIGHT_OFFSET = 200;

    public function __construct(
        private WorkflowGraphNodeView $from,
        private WorkflowGraphNodeView $to,
    ) {
    }

    public function path(): string
    {
        $fromX = (($this->from->columnStart() - 1) * self::COLUMN_WIDTH) + self::NODE_RIGHT_OFFSET;
        $toX = (($this->to->columnStart() - 1) * self::COLUMN_WIDTH) + self::NODE_LEFT_OFFSET;
        $fromY = $this->centerY($this->from);
        $toY = $this->centerY($this->to);
        $midX = $fromX + (($toX - $fromX) / 2);

        return sprintf(
            'M %.1f %.1f C %.1f %.1f %.1f %.1f %.1f %.1f',
            $fromX,
            $fromY,
            $midX,
            $fromY,
            $midX,
            $toY,
            $toX,
            $toY,
        );
    }

    private function centerY(WorkflowGraphNodeView $node): float
    {
        return (($node->rowStart() - 1) * self::ROW_HEIGHT) + (($node->rowSpan() * self::ROW_HEIGHT) / 2);
    }
}
