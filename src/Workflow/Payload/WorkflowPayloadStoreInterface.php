<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Payload;

use Fluxx\Entity\WorkflowPayload;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;

interface WorkflowPayloadStoreInterface
{
    /**
     * @param list<array<string, mixed>> $records
     * @param array<string, mixed> $metadata
     */
    public function storeStepInput(
        WorkflowRun $workflowRun,
        WorkflowStepRun $sourceStepRun,
        string $targetStepType,
        string $targetStepName,
        array $records,
        int $recordCount,
        int $sequence = 1,
        array $metadata = [],
    ): WorkflowPayload;

    /**
     * @return array<string, mixed>
     */
    public function load(WorkflowPayload $workflowPayload): array;
}
