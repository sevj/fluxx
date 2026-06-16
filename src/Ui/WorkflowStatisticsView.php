<?php

declare(strict_types=1);

namespace Fluxx\Ui;

final readonly class WorkflowStatisticsView
{
    /**
     * @param list<WorkflowStatisticsRangeView> $ranges
     * @param list<WorkflowStatisticsPointView> $points
     * @param list<int> $yAxisTicks
     */
    public function __construct(
        private string $selectedRange,
        private array $ranges,
        private array $points,
        private int $executionTotal,
        private int $errorTotal,
        private int $maxValue,
        private array $yAxisTicks,
    ) {
    }

    public function selectedRange(): string
    {
        return $this->selectedRange;
    }

    /**
     * @return list<WorkflowStatisticsRangeView>
     */
    public function ranges(): array
    {
        return $this->ranges;
    }

    /**
     * @return list<WorkflowStatisticsPointView>
     */
    public function points(): array
    {
        return $this->points;
    }

    public function executionTotal(): int
    {
        return $this->executionTotal;
    }

    public function errorTotal(): int
    {
        return $this->errorTotal;
    }

    public function maxValue(): int
    {
        return $this->maxValue;
    }

    /**
     * @return list<int>
     */
    public function yAxisTicks(): array
    {
        return $this->yAxisTicks;
    }

    public function isEmpty(): bool
    {
        return $this->executionTotal === 0;
    }
}
