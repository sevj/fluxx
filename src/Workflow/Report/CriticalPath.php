<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Report;

use Fluxx\Entity\WorkflowStepRun;
use Fluxx\Workflow\WorkflowDefinition;

final class CriticalPath
{
    /**
     * @param array<string, WorkflowStepRun> $stepRunsByCode
     * @return list<string> step codes, from the first step of the chain to the last
     */
    public static function compute(WorkflowDefinition $definition, array $stepRunsByCode): array
    {
        $finished = array_filter(
            $stepRunsByCode,
            static fn (WorkflowStepRun $stepRun, string $code): bool => $stepRun->finishedAt() !== null && self::isDefined($definition, $code),
            ARRAY_FILTER_USE_BOTH,
        );

        $current = self::latest($definition, $finished, array_keys($finished));
        $path = [];

        while ($current !== null && !in_array($current, $path, true)) {
            $path[] = $current;
            $current = self::latest($definition, $finished, $definition->step($current)->dependsOn());
        }

        return array_reverse($path);
    }

    /**
     * Finish times are stored to the second, so ties are frequent: the longer step wins, then the one
     * defined later in the workflow.
     *
     * @param array<string, WorkflowStepRun> $finished
     * @param list<string> $candidates
     */
    private static function latest(WorkflowDefinition $definition, array $finished, array $candidates): ?string
    {
        $best = null;
        $bestRank = null;

        foreach ($candidates as $code) {
            $stepRun = $finished[$code] ?? null;

            if ($stepRun === null) {
                continue;
            }

            $rank = [
                (int) $stepRun->finishedAt()->format('Uu'),
                $stepRun->durationMs() ?? 0,
                $definition->positionOf($code),
            ];

            if ($bestRank === null || $rank > $bestRank) {
                $best = $code;
                $bestRank = $rank;
            }
        }

        return $best;
    }

    private static function isDefined(WorkflowDefinition $definition, string $code): bool
    {
        foreach ($definition->steps() as $step) {
            if ($step->code() === $code) {
                return true;
            }
        }

        return false;
    }
}
