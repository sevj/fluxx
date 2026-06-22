<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Step;

use Fluxx\Mapper\MapperInterface;

interface TransformStepInterface extends ExecutableWorkflowStepInterface
{
    public function applyMapper(string $mapper, string|array $input): string|array;
    public function applyMappers(array $mappers, string|array $input): string|array;

}
