<?php

declare(strict_types=1);

namespace Fluxx\Ui;

final readonly class WorkflowGraphEdgeView
{
    private const COLUMN_WIDTH = 220;
    private const ROW_HEIGHT = 120;
    private const NODE_LEFT_OFFSET = 20;
    private const NODE_RIGHT_OFFSET = 200;
    public const CARD_HEIGHT = 86;
    public const CARD_END_INSET = 12;

    public function __construct(
        private WorkflowGraphNodeView $from,
        private WorkflowGraphNodeView $to,
        private ?float $targetY = null,
    ) {
    }

    public function path(): string
    {
        $fromX = (($this->from->columnStart() - 1) * self::COLUMN_WIDTH) + self::NODE_RIGHT_OFFSET;
        $toX = (($this->to->columnStart() - 1) * self::COLUMN_WIDTH) + self::NODE_LEFT_OFFSET;
        $fromY = self::laneCenter($this->from);
        $toY = $this->targetY ?? self::laneCenter($this->to);

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

    public static function laneCenter(WorkflowGraphNodeView $node): float
    {
        return (($node->rowStart() - 1) * self::ROW_HEIGHT) + (($node->rowSpan() * self::ROW_HEIGHT) / 2);
    }
}
