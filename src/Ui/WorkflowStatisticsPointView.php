<?php

declare(strict_types=1);

namespace Fluxx\Ui;

final readonly class WorkflowStatisticsPointView
{
    public function __construct(
        private string $label,
        private string $axisLabel,
        private int $executionCount,
        private int $errorCount,
        private float $executionHeightPercent,
        private float $errorHeightPercent,
    ) {
    }

    public function label(): string
    {
        return $this->label;
    }

    public function axisLabel(): string
    {
        return $this->axisLabel;
    }

    public function executionCount(): int
    {
        return $this->executionCount;
    }

    public function errorCount(): int
    {
        return $this->errorCount;
    }

    public function executionHeightPercent(): float
    {
        return $this->executionHeightPercent;
    }

    public function errorHeightPercent(): float
    {
        return $this->errorHeightPercent;
    }
}
