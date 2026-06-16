<?php

declare(strict_types=1);

namespace Fluxx\Ui;

final readonly class SystemHealthCheckView
{
    public function __construct(
        private string $labelKey,
        private string $state,
        private string $detail,
    ) {
    }

    public function labelKey(): string
    {
        return $this->labelKey;
    }

    public function state(): string
    {
        return $this->state;
    }

    public function detail(): string
    {
        return $this->detail;
    }
}
