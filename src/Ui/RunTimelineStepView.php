<?php

declare(strict_types=1);

namespace Fluxx\Ui;

use DateTimeImmutable;

final readonly class RunTimelineStepView
{
    public function __construct(
        private string $code,
        private string $name,
        private string $status,
        private string $typeTone,
        private ?string $typeToneStyle,
        private int $position,
        private ?DateTimeImmutable $startedAt,
        private ?DateTimeImmutable $finishedAt,
        private ?int $durationMs,
        private ?float $leftPercent,
        private ?float $widthPercent,
        private bool $isRunning,
    ) {
    }

    public function code(): string
    {
        return $this->code;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function typeTone(): string
    {
        return $this->typeTone;
    }

    public function typeToneStyle(): ?string
    {
        return $this->typeToneStyle;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function startedAt(): ?DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function finishedAt(): ?DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function durationMs(): ?int
    {
        return $this->durationMs;
    }

    public function leftPercent(): ?float
    {
        return $this->leftPercent;
    }

    public function widthPercent(): ?float
    {
        return $this->widthPercent;
    }

    public function isRunning(): bool
    {
        return $this->isRunning;
    }

    public function isPending(): bool
    {
        return $this->leftPercent === null;
    }
}
