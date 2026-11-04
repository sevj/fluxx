<?php

declare(strict_types=1);

namespace Fluxx\Ui;

use Symfony\Component\Translation\TranslatableMessage;

final readonly class WorkflowStatisticsPointView
{
    public function __construct(
        private TranslatableMessage $label,
        private ?TranslatableMessage $axisLabel,
        private int $executionCount,
        private int $errorCount,
        private float $executionHeightPercent,
        private float $errorHeightPercent,
    ) {
    }

    public function label(): TranslatableMessage
    {
        return $this->label;
    }

    public function axisLabel(): ?TranslatableMessage
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
