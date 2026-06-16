<?php

declare(strict_types=1);

namespace Fluxx\Ui;

final readonly class SystemHealthFindingView
{
    public function __construct(
        private string $state,
        private string $title,
        private string $message,
    ) {
    }

    public function state(): string
    {
        return $this->state;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function message(): string
    {
        return $this->message;
    }
}
