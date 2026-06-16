<?php

declare(strict_types=1);

namespace Fluxx\Ui;

final readonly class SystemHealthFactView
{
    public function __construct(
        private string $labelKey,
        private string $value,
        private string $tone = 'default',
    ) {
    }

    public function labelKey(): string
    {
        return $this->labelKey;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function tone(): string
    {
        return $this->tone;
    }
}
