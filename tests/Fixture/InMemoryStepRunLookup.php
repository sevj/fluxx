<?php

declare(strict_types=1);

namespace Fluxx\Tests\Fixture;

use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;
use Fluxx\Repository\WorkflowStepRunLookupInterface;

final class InMemoryStepRunLookup implements WorkflowStepRunLookupInterface
{
    /** @var array<int, WorkflowStepRun> */
    private array $stepRuns = [];

    /** @var array<string, WorkflowStepRun> */
    private array $completedByKey = [];

    public function add(WorkflowStepRun $stepRun): void
    {
        $this->stepRuns[] = $stepRun;
    }

    public function addCompleted(WorkflowStepRun $stepRun, string $workflowName, string $stepName, string $idempotenceKey): void
    {
        $this->add($stepRun);
        $this->completedByKey[$workflowName . '|' . $stepName . '|' . $idempotenceKey] = $stepRun;
    }

    public function findByWorkflowRunOrdered(WorkflowRun $workflowRun): array
    {
        $matching = [];

        foreach ($this->stepRuns as $stepRun) {
            if ($stepRun->workflowRun() === $workflowRun) {
                $matching[] = $stepRun;
            }
        }

        usort($matching, static fn (WorkflowStepRun $a, WorkflowStepRun $b): int => $a->position() <=> $b->position());

        return $matching;
    }

    public function findLatestByWorkflowRunAndStepName(WorkflowRun $workflowRun, string $stepName): ?WorkflowStepRun
    {
        $latest = null;

        foreach ($this->stepRuns as $stepRun) {
            if ($stepRun->workflowRun() === $workflowRun && $stepRun->stepName() === $stepName) {
                $latest = $stepRun;
            }
        }

        return $latest;
    }

    public function findCompletedByWorkflowRunAndStepNames(WorkflowRun $workflowRun, array $stepNames): array
    {
        $needed = array_flip($stepNames);
        $matching = [];

        foreach ($this->stepRuns as $stepRun) {
            if ($stepRun->workflowRun() !== $workflowRun) {
                continue;
            }

            if (!isset($needed[$stepRun->stepName()])) {
                continue;
            }

            if ($stepRun->status()->value !== 'completed') {
                continue;
            }

            $matching[$stepRun->stepName()] = $stepRun;
        }

        return $matching;
    }

    public function findLatestCompletedByWorkflowNameAndStepNameAndIdempotenceKey(
        string $workflowName,
        string $stepName,
        string $idempotenceKey,
    ): ?WorkflowStepRun {
        return $this->completedByKey[$workflowName . '|' . $stepName . '|' . $idempotenceKey] ?? null;
    }
}
