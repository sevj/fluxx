<?php

declare(strict_types=1);

namespace Fluxx\Workflow;

interface WorkflowInterface
{
    public function definition(): WorkflowDefinition;
}
