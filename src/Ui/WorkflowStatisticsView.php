<?php

declare(strict_types=1);

namespace Fluxx\Ui;

final readonly class WorkflowStatisticsView
{
    /**
     * @param list<WorkflowStatisticsRangeView> $ranges
     * @param list<WorkflowStatisticsPointView> $points
     * @param list<WorkflowStatisticsMetricView> $metrics
     * @param list<WorkflowStepStatisticsView> $stepMetrics
     * @param list<int> $yAxisTicks
     */
    public function __construct(
        private string $selectedRange,
        private array $ranges,
        private array $points,
        private array $metrics,
        private array $stepMetrics,
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

    /**
     * @return list<WorkflowStatisticsMetricView>
     */
    public function metrics(): array
    {
        return $this->metrics;
    }

    /**
     * @return list<WorkflowStepStatisticsView>
     */
    public function stepMetrics(): array
    {
        return $this->stepMetrics;
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
