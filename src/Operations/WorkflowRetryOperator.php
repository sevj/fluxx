<?php

declare(strict_types=1);

namespace Fluxx\Operations;

use Fluxx\Workflow\Relaunch\WorkflowRelaunchMode;
use Fluxx\Workflow\Relaunch\WorkflowRelaunchService;

class WorkflowRetryOperator
{
    public function __construct(
        private readonly WorkflowRelaunchService $workflowRelaunchService,
    ) {
    }

    public function retryRun(
        string $runId,
        string $trigger = 'cli',
        ?string $reason = null,
        ?string $operatorUser = null,
    ): string {
        return $this->workflowRelaunchService->relaunch(
            originalRunId: $runId,
            mode: WorkflowRelaunchMode::Full,
            trigger: $trigger,
            reason: $reason,
            operatorUser: $operatorUser,
        );
    }

    public function retryStep(
        string $runId,
        string $stepCode,
        string $trigger = 'cli',
        ?string $reason = null,
        ?string $operatorUser = null,
    ): string {
        return $this->workflowRelaunchService->relaunch(
            originalRunId: $runId,
            mode: WorkflowRelaunchMode::Step,
            restartStepCode: $stepCode,
            trigger: $trigger,
            reason: $reason,
            operatorUser: $operatorUser,
        );
    }
}
