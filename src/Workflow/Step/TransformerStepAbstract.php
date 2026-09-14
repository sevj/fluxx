<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Step;

use Fluxx\Mapper\MapperInterface;

abstract class TransformerStepAbstract implements TransformStepInterface
{
    /**
     * @param iterable<string, MapperInterface> $fluxxMapper
     */
    public function __construct(
        protected iterable $fluxxMapper,
    ) {
        $this->fluxxMapper = iterator_to_array($this->fluxxMapper);
    }

    public function applyMapper(string $mapper, string|array|null $input): string|array
    {
        if (null === $input) {
            return '';
        }

        if (isset($this->fluxxMapper[$mapper])) {
            return $this->fluxxMapper[$mapper]->treat($input);
        }

        return $input;
    }

    public function applyMappers(array $mappers, string|array|null $input): string|array
    {
        foreach ($mappers as $mapper) {
            $input = $this->applyMapper($mapper, $input);
        }

        return $input;
    }
}
