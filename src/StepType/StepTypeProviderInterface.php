<?php

declare(strict_types=1);

namespace Fluxx\StepType;

interface StepTypeProviderInterface
{
    /**
     * @return list<StepTypeDefinition>
     */
    public function stepTypes(): array;
}
