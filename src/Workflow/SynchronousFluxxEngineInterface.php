<?php

declare(strict_types=1);

namespace Fluxx\Workflow;

interface SynchronousFluxxEngineInterface
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function runWithResult(
        string $workflowCode,
        string $trigger = 'manual',
        ?string $batchId = null,
        array $metadata = [],
    ): SynchronousWorkflowResult;
}
