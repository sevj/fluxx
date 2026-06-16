<?php

declare(strict_types=1);

namespace Fluxx\StepType;

use Fluxx\Entity\Enum\WorkflowStepType;

final class BuiltinStepTypeProvider implements StepTypeProviderInterface
{
    public function stepTypes(): array
    {
        return [
            new StepTypeDefinition(WorkflowStepType::Read, 'Read', 'read'),
            new StepTypeDefinition(WorkflowStepType::Splitter, 'Splitter', 'splitter'),
            new StepTypeDefinition(WorkflowStepType::Transform, 'Transform', 'transform'),
            new StepTypeDefinition(WorkflowStepType::Write, 'Write', 'write'),
            new StepTypeDefinition(WorkflowStepType::Linker, 'Linker', 'linker'),
        ];
    }
}
