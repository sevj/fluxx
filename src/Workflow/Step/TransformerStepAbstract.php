<?php

namespace Fluxx\Workflow\Step;

use Fluxx\Mapper\MapperInterface;

abstract class TransformerStepAbstract
{
    public function __construct(
        protected $fluxxMapper
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
    }

    public function applyMappers(array $mappers, string|array|null $input): string|array
    {
        foreach ($mappers as $mapper) {
            $input = $this->applyMapper($mapper, $input);
        }

        return $input;
    }
}