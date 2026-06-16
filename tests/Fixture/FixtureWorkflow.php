<?php

declare(strict_types=1);

namespace Fluxx\Tests\Fixture;

use Fluxx\Workflow\WorkflowDefinition;
use Fluxx\Workflow\WorkflowInterface;

final readonly class FixtureWorkflow implements WorkflowInterface
{
    public function __construct(
        private WorkflowDefinition $definition,
    ) {
    }

    public function definition(): WorkflowDefinition
    {
        return $this->definition;
    }
}
