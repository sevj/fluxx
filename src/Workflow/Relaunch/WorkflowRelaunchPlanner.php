<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Relaunch;

use Fluxx\Workflow\WorkflowDefinition;
use InvalidArgumentException;

final class WorkflowRelaunchPlanner
{
    public function plan(
        WorkflowDefinition $definition,
        WorkflowRelaunchMode $mode,
        ?string $restartStepCode = null,
    ): WorkflowRelaunchPlan {
        if ($mode === WorkflowRelaunchMode::Full) {
            return new WorkflowRelaunchPlan(
                mode: $mode,
                entryStepCodes: array_map(
                    static fn ($step): string => $step->code(),
                    $definition->rootSteps(),
                ),
                targetStepCodes: array_map(
                    static fn ($step): string => $step->code(),
                    $definition->steps(),
                ),
                preservedStepCodes: [],
            );
        }

        if ($restartStepCode === null || trim($restartStepCode) === '') {
            throw new InvalidArgumentException(sprintf(
                'A restart step is required for relaunch mode "%s".',
                $mode->value,
            ));
        }

        $restartStepCode = trim($restartStepCode);

        return new WorkflowRelaunchPlan(
            mode: $mode,
            entryStepCodes: [$restartStepCode],
            targetStepCodes: array_map(
                static fn ($step): string => $step->code(),
                $definition->descendantsOf($restartStepCode),
            ),
            preservedStepCodes: array_map(
                static fn ($step): string => $step->code(),
                $definition->ancestorsOf($restartStepCode),
            ),
        );
    }

    /**
     * Restarts every step that did not complete, from the first ones whose dependencies all
     * completed, and keeps every completed step outside their descendants, whatever its branch.
     *
     * @param list<string> $completedStepCodes
     */
    public function planResume(WorkflowDefinition $definition, array $completedStepCodes): WorkflowRelaunchPlan
    {
        $completed = array_fill_keys($completedStepCodes, true);
        $entryStepCodes = [];

        foreach ($definition->steps() as $step) {
            if (isset($completed[$step->code()])) {
                continue;
            }

            $dependenciesCompleted = array_filter(
                $step->dependsOn(),
                static fn (string $dependencyCode): bool => !isset($completed[$dependencyCode]),
            ) === [];

            if ($dependenciesCompleted) {
                $entryStepCodes[] = $step->code();
            }
        }

        if ($entryStepCodes === []) {
            throw new InvalidArgumentException(sprintf(
                'Workflow "%s" has no step left to resume.',
                $definition->code(),
            ));
        }

        $targets = [];
        foreach ($entryStepCodes as $entryStepCode) {
            foreach ($definition->descendantsOf($entryStepCode) as $descendant) {
                $targets[$descendant->code()] = true;
            }
        }

        $targetStepCodes = [];
        $preservedStepCodes = [];
        foreach ($definition->steps() as $step) {
            if (isset($targets[$step->code()])) {
                $targetStepCodes[] = $step->code();
            } else {
                $preservedStepCodes[] = $step->code();
            }
        }

        return new WorkflowRelaunchPlan(
            mode: WorkflowRelaunchMode::Resume,
            entryStepCodes: $entryStepCodes,
            targetStepCodes: $targetStepCodes,
            preservedStepCodes: $preservedStepCodes,
        );
    }
}
