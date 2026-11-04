<?php

declare(strict_types=1);

namespace Fluxx\Ui;

use DateTimeImmutable;

final readonly class RunRelaunchView
{
    public function __construct(
        private string $runId,
        private string $status,
        private DateTimeImmutable $createdAt,
    ) {
    }

    public function runId(): string
    {
        return $this->runId;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
