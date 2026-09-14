<?php

declare(strict_types=1);

namespace Fluxx\Ui;

final readonly class WorkflowChoiceView
{
    public function __construct(
        private string $code,
        private string $name,
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
}
