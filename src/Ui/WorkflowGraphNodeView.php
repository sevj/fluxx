<?php

declare(strict_types=1);

namespace Fluxx\Ui;

final readonly class WorkflowGraphNodeView
{
    public function __construct(
        private WorkflowStepDefinitionView $step,
        private int $rowStart,
        private int $columnStart,
        private int $rowSpan = 1,
        private int $columnSpan = 1,
    ) {
    }

    public function step(): WorkflowStepDefinitionView
    {
        return $this->step;
    }

    public function columnStart(): int
    {
        return $this->columnStart;
    }

    public function rowStart(): int
    {
        return $this->rowStart;
    }

    public function rowSpan(): int
    {
        return $this->rowSpan;
    }

    public function columnSpan(): int
    {
        return $this->columnSpan;
    }

    public function rowEnd(): int
    {
        return $this->rowStart + $this->rowSpan - 1;
    }

    public function columnEnd(): int
    {
        return $this->columnStart + $this->columnSpan - 1;
    }
}
