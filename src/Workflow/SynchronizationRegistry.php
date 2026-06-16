<?php

declare(strict_types=1);

namespace Fluxx\Workflow;

use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class SynchronizationRegistry
{
    /**
     * @var array<string, WorkflowInterface>
     */
    private array $workflows = [];

    /**
     * @param iterable<WorkflowInterface> $workflows
     */
    public function __construct(
        #[AutowireIterator('fluxx.workflow')]
        iterable $workflows,
    ) {
        foreach ($workflows as $workflow) {
            $this->workflows[$workflow->definition()->code()] = $workflow;
        }
    }

    /**
     * @return list<WorkflowInterface>
     */
    public function all(): array
    {
        return array_values($this->workflows);
    }

    public function get(string $name): WorkflowInterface
    {
        if (!isset($this->workflows[$name])) {
            throw new InvalidArgumentException(sprintf('Workflow "%s" is not registered.', $name));
        }

        return $this->workflows[$name];
    }

    public function has(string $name): bool
    {
        return isset($this->workflows[$name]);
    }
}
