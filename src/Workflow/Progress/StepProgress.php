<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Progress;

/**
 * Point-in-time progress of a running step, as reported by its handler.
 */
final readonly class StepProgress
{
    public function __construct(
        private int $done,
        private int $total,
        private string $label = '',
    ) {
    }

    public function done(): int
    {
        return $this->done;
    }

    public function total(): int
    {
        return $this->total;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function percent(): int
    {
        if ($this->total <= 0) {
            return 0;
        }

        return (int) min(100, max(0, floor(($this->done / $this->total) * 100)));
    }
}
