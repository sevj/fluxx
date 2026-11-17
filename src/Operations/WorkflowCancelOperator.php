<?php

declare(strict_types=1);

namespace Fluxx\Operations;

use Fluxx\Workflow\Cancellation\WorkflowCancellationService;

class WorkflowCancelOperator
{
    public function __construct(
        private readonly WorkflowCancellationService $workflowCancellationService,
    ) {
    }

    /**
     * Returns false when the run is no longer active and cannot be cancelled.
     */
    public function cancelRun(
        string $runId,
        string $trigger = 'cli',
        ?string $reason = null,
        ?string $operatorUser = null,
    ): bool {
        return $this->workflowCancellationService->cancel($runId, $trigger, $reason, $operatorUser);
    }
}
