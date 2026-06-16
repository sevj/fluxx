<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Runtime;

use Fluxx\Entity\Enum\WorkflowRunStatus;
use Fluxx\Entity\Enum\WorkflowStepRunStatus;
use Fluxx\Entity\WorkflowStepRun;
use Fluxx\Workflow\WorkflowDefinition;

final class WorkflowRunCompletionDecider
{
    /**
     * @var array<string, string>
     */
    private array $stepStates = [];

    /**
     * @param array<string, WorkflowStepRun> $latestStepRunsByCode
     * @param list<string> $targetStepCodes
     */
    public function decide(WorkflowDefinition $definition, array $latestStepRunsByCode, array $targetStepCodes): ?WorkflowRunStatus
    {
        $this->stepStates = [];
        $completedCount = 0;
        $failedCount = 0;
        $hasRunningStep = false;
        $hasRunnablePendingStep = false;
        $hasWaitingPendingStep = false;

        foreach ($targetStepCodes as $stepCode) {
            $stepState = $this->resolveStepState($definition, $latestStepRunsByCode, $stepCode);

            if ($stepState === 'completed') {
                ++$completedCount;
                continue;
            }

            if ($stepState === 'failed') {
                ++$failedCount;
                continue;
            }

            if ($stepState === 'running' || $stepState === 'retrying') {
                $hasRunningStep = true;
                continue;
            }

            if ($stepState === 'runnable') {
                $hasRunnablePendingStep = true;
                continue;
            }

            if ($stepState === 'waiting') {
                $hasWaitingPendingStep = true;
            }
        }

        if ($completedCount === count($targetStepCodes)) {
            return WorkflowRunStatus::Completed;
        }

        if ($hasRunningStep || $hasRunnablePendingStep || $hasWaitingPendingStep) {
            return null;
        }

        if ($failedCount === 0) {
            return null;
        }

        if ($completedCount > 0) {
            return WorkflowRunStatus::PartiallyFailed;
        }

        return WorkflowRunStatus::Failed;
    }

    /**
     * @param array<string, WorkflowStepRun> $latestStepRunsByCode
     */
    private function resolveStepState(
        WorkflowDefinition $definition,
        array $latestStepRunsByCode,
        string $stepCode,
    ): string {
        if (isset($this->stepStates[$stepCode])) {
            return $this->stepStates[$stepCode];
        }

        $stepRun = $latestStepRunsByCode[$stepCode] ?? null;
        $status = $stepRun?->status();

        if ($status === WorkflowStepRunStatus::Completed) {
            return $this->stepStates[$stepCode] = 'completed';
        }

        if ($status === WorkflowStepRunStatus::Failed) {
            return $this->stepStates[$stepCode] = 'failed';
        }

        if ($status === WorkflowStepRunStatus::Cancelled) {
            return $this->stepStates[$stepCode] = 'failed';
        }

        if ($status === WorkflowStepRunStatus::Running) {
            return $this->stepStates[$stepCode] = 'running';
        }

        if ($status === WorkflowStepRunStatus::Retrying) {
            return $this->stepStates[$stepCode] = 'retrying';
        }

        $dependencyStates = array_map(
            fn (string $dependencyCode): string => $this->resolveStepState($definition, $latestStepRunsByCode, $dependencyCode),
            $definition->step($stepCode)->dependsOn(),
        );

        if ($dependencyStates === [] || $this->allDependenciesCompleted($dependencyStates)) {
            return $this->stepStates[$stepCode] = 'runnable';
        }

        foreach ($dependencyStates as $dependencyState) {
            if (in_array($dependencyState, ['running', 'retrying', 'waiting', 'runnable'], true)) {
                return $this->stepStates[$stepCode] = 'waiting';
            }
        }

        return $this->stepStates[$stepCode] = 'blocked';
    }

    /**
     * @param list<string> $dependencyStates
     */
    private function allDependenciesCompleted(array $dependencyStates): bool
    {
        foreach ($dependencyStates as $dependencyState) {
            if ($dependencyState !== 'completed') {
                return false;
            }
        }

        return true;
    }
}
