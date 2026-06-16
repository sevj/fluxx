<?php

declare(strict_types=1);

namespace Fluxx\Ui;

final readonly class WorkflowStatisticsRangeView
{
    public function __construct(
        private string $value,
        private string $labelKey,
        private bool $selected,
    ) {
    }

    public function value(): string
    {
        return $this->value;
    }

    public function labelKey(): string
    {
        return $this->labelKey;
    }

    public function selected(): bool
    {
        return $this->selected;
    }
}
