<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Relaunch;

final readonly class WorkflowRelaunchPlan
{
    /**
     * @param list<string> $entryStepCodes
     * @param list<string> $targetStepCodes
     * @param list<string> $preservedStepCodes
     */
    public function __construct(
        private WorkflowRelaunchMode $mode,
        private array $entryStepCodes,
        private array $targetStepCodes,
        private array $preservedStepCodes,
    ) {
    }

    public function mode(): WorkflowRelaunchMode
    {
        return $this->mode;
    }

    /**
     * @return list<string>
     */
    public function entryStepCodes(): array
    {
        return $this->entryStepCodes;
    }

    /**
     * @return list<string>
     */
    public function targetStepCodes(): array
    {
        return $this->targetStepCodes;
    }

    /**
     * @return list<string>
     */
    public function preservedStepCodes(): array
    {
        return $this->preservedStepCodes;
    }
}
