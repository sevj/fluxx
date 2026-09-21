<?php

declare(strict_types=1);

namespace Fluxx\Workflow;

use Fluxx\Entity\Enum\WorkflowStepType;
use Fluxx\Workflow\Step\ExecutableWorkflowStepInterface;
use Fluxx\Workflow\Retry\WorkflowRetryPolicy;
use Fluxx\Workflow\Step\WorkflowStepIdempotence;

final readonly class WorkflowStepDefinition
{
    /**
     * @param list<string> $dependsOn
     */
    public function __construct(
        private string $code,
        private string $name,
        private WorkflowStepType|string $type,
        private ExecutableWorkflowStepInterface $handler,
        private array $dependsOn = [],
        private ?WorkflowStepIdempotence $idempotence = null,
        private ?WorkflowRetryPolicy $retryPolicy = null,
        private ?int $chunkSize = null,
    ) {
    }

    public function code(): string
    {
        return $this->code;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function type(): string
    {
        return $this->type instanceof WorkflowStepType ? $this->type->value : $this->type;
    }

    public function typeEnum(): ?WorkflowStepType
    {
        return $this->type instanceof WorkflowStepType ? $this->type : WorkflowStepType::tryFrom($this->type);
    }

    public function handler(): ExecutableWorkflowStepInterface
    {
        return $this->handler;
    }

    /**
     * @return list<string>
     */
    public function dependsOn(): array
    {
        return $this->dependsOn;
    }

    public function isRoot(): bool
    {
        return $this->dependsOn === [];
    }

    public function idempotence(): ?WorkflowStepIdempotence
    {
        return $this->idempotence;
    }

    public function retryPolicy(): ?WorkflowRetryPolicy
    {
        return $this->retryPolicy;
    }

    public function chunkSize(): ?int
    {
        return $this->chunkSize;
    }
}
