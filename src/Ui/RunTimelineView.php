<?php

declare(strict_types=1);

namespace Fluxx\Ui;

use DateTimeImmutable;

final readonly class RunTimelineView
{
    /**
     * @param list<RunTimelineStepView> $steps
     */
    public function __construct(
        private DateTimeImmutable $windowStart,
        private DateTimeImmutable $windowEnd,
        private int $totalDurationMs,
        private bool $isLive,
        private bool $hasTiming,
        private array $steps,
    ) {
    }

    public function windowStart(): DateTimeImmutable
    {
        return $this->windowStart;
    }

    public function windowEnd(): DateTimeImmutable
    {
        return $this->windowEnd;
    }

    public function totalDurationMs(): int
    {
        return $this->totalDurationMs;
    }

    public function isLive(): bool
    {
        return $this->isLive;
    }

    public function hasTiming(): bool
    {
        return $this->hasTiming;
    }

    /**
     * @return list<RunTimelineStepView>
     */
    public function steps(): array
    {
        return $this->steps;
    }
}
