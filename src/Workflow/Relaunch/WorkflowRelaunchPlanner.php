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
}
