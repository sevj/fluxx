<?php

declare(strict_types=1);

namespace Fluxx\Ui;

final readonly class WorkflowStepDefinitionView
{
    /**
     * @param list<string> $dependsOn
     */
    public function __construct(
        private string $type,
        private string $typeLabel,
        private string $typeTone,
        private ?string $typeToneStyle,
        private string $code,
        private string $name,
        private array $dependsOn,
        private int $level,
    ) {
    }

    public function type(): string
    {
        return $this->type;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function typeLabel(): string
    {
        return $this->typeLabel;
    }

    public function typeTone(): string
    {
        return $this->typeTone;
    }

    public function typeToneStyle(): ?string
    {
        return $this->typeToneStyle;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return list<string>
     */
    public function dependsOn(): array
    {
        return $this->dependsOn;
    }

    public function level(): int
    {
        return $this->level;
    }
}
