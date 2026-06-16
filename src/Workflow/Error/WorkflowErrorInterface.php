<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Error;

interface WorkflowErrorInterface
{
    public function workflowErrorCategory(): WorkflowErrorCategory;

    public function workflowErrorCode(): ?string;

    /**
     * @return array<string, mixed>
     */
    public function workflowErrorContext(): array;
}
