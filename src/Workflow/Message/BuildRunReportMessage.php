<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Message;

final readonly class BuildRunReportMessage
{
    public function __construct(
        private string $runId,
    ) {
    }

    public function runId(): string
    {
        return $this->runId;
    }
}
