<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Runtime;

use Fluxx\Entity\WorkflowPayload;
use Fluxx\Workflow\WorkflowDefinition;

/**
 * A step output is stored once per downstream step, and branch outputs can make
 * those copies differ. A step reading an ancestor must get the copy sent along
 * its own lineage, which is the one it would have received step by step.
 */
final class AncestorPayloadSelector
{
    /**
     * @param list<WorkflowPayload> $payloads payloads produced by the ancestor, in order
     * @return list<WorkflowPayload>
     */
    public static function select(WorkflowDefinition $definition, string $readerStepCode, array $payloads): array
    {
        $lineage = [$readerStepCode => true];

        foreach ($definition->ancestorsOf($readerStepCode) as $ancestor) {
            $lineage[$ancestor->code()] = true;
        }

        $selectedTarget = null;
        $selected = [];

        foreach ($payloads as $payload) {
            $target = $payload->targetStepName();

            if (!isset($lineage[$target])) {
                continue;
            }

            $selectedTarget ??= $target;

            if ($target === $selectedTarget) {
                $selected[] = $payload;
            }
        }

        return $selected;
    }
}
