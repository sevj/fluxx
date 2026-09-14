<?php

declare(strict_types=1);

namespace Fluxx\Workflow;

interface FluxxEngineInterface
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function run(
        string $workflowCode,
        string $trigger = 'manual',
        ?string $batchId = null,
        array $metadata = [],
    ): string;
}
