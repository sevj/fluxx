<?php

declare(strict_types=1);

namespace Fluxx\Ui;

use DateTimeImmutable;

final readonly class ActiveRunStepView
{
    public function __construct(
        private string $code,
        private string $name,
        private string $status,
        private string $typeTone,
        private ?string $typeToneStyle,
        private ?DateTimeImmutable $startedAt,
        private ?string $elapsedLabel,
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

    public function startedAt(): ?DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function elapsedLabel(): ?string
    {
        return $this->elapsedLabel;
    }
}
