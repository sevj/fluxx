<?php

declare(strict_types=1);

namespace Fluxx\Workflow;

use Fluxx\Workflow\Lock\WorkflowExecutionLockConfiguration;
use Fluxx\Workflow\Retry\WorkflowRetryPolicy;
use InvalidArgumentException;

final readonly class WorkflowDefinition
{
    /**
     * @var array<string, WorkflowStepDefinition>
     */
    private array $stepMap;

    /**
     * @param list<WorkflowStepDefinition> $steps
     */
    public function __construct(
        private string $code,
        private string $name,
        private string $sourceSystem,
        private string $targetSystem,
        private array $steps,
        private ?WorkflowExecutionLockConfiguration $lock = null,
        private ?WorkflowRetryPolicy $retryPolicy = null,
    ) {
        $stepMap = [];

        foreach ($this->steps as $step) {
            if (isset($stepMap[$step->code()])) {
                throw new InvalidArgumentException(sprintf(
                    'Workflow "%s" defines duplicate step code "%s".',
                    $this->code,
                    $step->code(),
                ));
            }

            $stepMap[$step->code()] = $step;
        }

        foreach ($this->steps as $step) {
            foreach ($step->dependsOn() as $dependencyCode) {
                if (!isset($stepMap[$dependencyCode])) {
                    throw new InvalidArgumentException(sprintf(
                        'Workflow "%s" step "%s" depends on unknown step "%s".',
                        $this->code,
                        $step->code(),
                        $dependencyCode,
                    ));
                }
            }
        }

        $this->stepMap = $stepMap;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function sourceSystem(): string
    {
        return $this->sourceSystem;
    }

    public function targetSystem(): string
    {
        return $this->targetSystem;
    }

    public function lock(): ?WorkflowExecutionLockConfiguration
    {
        return $this->lock;
    }

    public function retryPolicy(): ?WorkflowRetryPolicy
    {
        return $this->retryPolicy;
    }

    /**
     * @return list<WorkflowStepDefinition>
     */
    public function steps(): array
    {
        return $this->steps;
    }

    /**
     * @return list<WorkflowStepDefinition>
     */
    public function rootSteps(): array
    {
        return array_values(array_filter(
            $this->steps,
            static fn (WorkflowStepDefinition $step): bool => $step->isRoot(),
        ));
    }

    public function step(string $stepCode): WorkflowStepDefinition
    {
        if (!isset($this->stepMap[$stepCode])) {
            throw new InvalidArgumentException(sprintf(
                'Workflow "%s" does not define step "%s".',
                $this->code,
                $stepCode,
            ));
        }

        return $this->stepMap[$stepCode];
    }

    /**
     * @return list<WorkflowStepDefinition>
     */
    public function downstreamSteps(string $stepCode): array
    {
        return array_values(array_filter(
            $this->steps,
            static fn (WorkflowStepDefinition $step): bool => in_array($stepCode, $step->dependsOn(), true),
        ));
    }

    /**
     * @return list<WorkflowStepDefinition>
     */
    public function descendantsOf(string $stepCode): array
    {
        $this->step($stepCode);
        $descendantCodes = [$stepCode => true];
        $queue = [$stepCode];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($this->downstreamSteps($current) as $downstreamStep) {
                if (isset($descendantCodes[$downstreamStep->code()])) {
                    continue;
                }

                $descendantCodes[$downstreamStep->code()] = true;
                $queue[] = $downstreamStep->code();
            }
        }

        return array_values(array_filter(
            $this->steps,
            static fn (WorkflowStepDefinition $step): bool => isset($descendantCodes[$step->code()]),
        ));
    }

    /**
     * @return list<WorkflowStepDefinition>
     */
    public function ancestorsOf(string $stepCode): array
    {
        $step = $this->step($stepCode);
        $ancestorCodes = [];

        $collect = function (WorkflowStepDefinition $definition) use (&$collect, &$ancestorCodes): void {
            foreach ($definition->dependsOn() as $dependencyCode) {
                if (isset($ancestorCodes[$dependencyCode])) {
                    continue;
                }

                $ancestorCodes[$dependencyCode] = true;
                $collect($this->step($dependencyCode));
            }
        };

        $collect($step);

        return array_values(array_filter(
            $this->steps,
            static fn (WorkflowStepDefinition $candidate): bool => isset($ancestorCodes[$candidate->code()]),
        ));
    }

    public function positionOf(string $stepCode): int
    {
        foreach ($this->steps as $index => $step) {
            if ($step->code() === $stepCode) {
                return $index + 1;
            }
        }

        throw new InvalidArgumentException(sprintf(
            'Workflow "%s" does not define step "%s".',
            $this->code,
            $stepCode,
        ));
    }
}
