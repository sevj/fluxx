<?php

declare(strict_types=1);

namespace Fluxx\StepType;

use Fluxx\Entity\Enum\WorkflowStepType;

final class BuiltinStepTypeProvider implements StepTypeProviderInterface
{
    public function stepTypes(): array
    {
        return [
            new StepTypeDefinition(WorkflowStepType::Read, 'step_type.read', 'read'),
            new StepTypeDefinition(WorkflowStepType::Splitter, 'step_type.splitter', 'splitter'),
            new StepTypeDefinition(WorkflowStepType::Transform, 'step_type.transform', 'transform'),
            new StepTypeDefinition(WorkflowStepType::Write, 'step_type.write', 'write'),
            new StepTypeDefinition(WorkflowStepType::Linker, 'step_type.linker', 'linker'),
        ];
    }
}
