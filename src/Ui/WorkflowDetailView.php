<?php

declare(strict_types=1);

namespace Fluxx\Ui;

use DateTimeImmutable;

final readonly class WorkflowDetailView
{
    /**
     * @param list<WorkflowStepDefinitionView> $steps
     * @param list<WorkflowStepRowView> $stepRows
     * @param list<WorkflowGraphEdgeView> $graphEdges
     */
    public function __construct(
        private string $code,
        private string $name,
        private string $sourceSystem,
        private string $targetSystem,
        private int $graphColumnCount,
        private int $graphRowCount,
        private int $executionCount,
        private int $errorCount,
        private ?DateTimeImmutable $lastExecutionAt,
        private ?DateTimeImmutable $lastErrorAt,
        private array $steps,
        private array $stepRows,
        private array $graphEdges,
        private WorkflowExecutionPage $executionPage,
        private WorkflowStatisticsView $statistics,
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

    public function sourceSystem(): string
    {
        return $this->sourceSystem;
    }

    public function targetSystem(): string
    {
        return $this->targetSystem;
    }

    public function graphColumnCount(): int
    {
        return $this->graphColumnCount;
    }

    public function graphRowCount(): int
    {
        return $this->graphRowCount;
    }

    public function graphViewBox(): string
    {
        return sprintf('0 0 %d %d', $this->graphColumnCount * 220, $this->graphRowCount * 120);
    }

    public function executionCount(): int
    {
        return $this->executionCount;
    }

    public function errorCount(): int
    {
        return $this->errorCount;
    }

    public function lastExecutionAt(): ?DateTimeImmutable
    {
        return $this->lastExecutionAt;
    }

    public function lastErrorAt(): ?DateTimeImmutable
    {
        return $this->lastErrorAt;
    }

    /**
     * @return list<WorkflowStepDefinitionView>
     */
    public function steps(): array
    {
        return $this->steps;
    }

    /**
     * @return list<WorkflowStepRowView>
     */
    public function stepRows(): array
    {
        return $this->stepRows;
    }

    /**
     * @return list<WorkflowGraphEdgeView>
     */
    public function graphEdges(): array
    {
        return $this->graphEdges;
    }

    public function executionPage(): WorkflowExecutionPage
    {
        return $this->executionPage;
    }

    public function statistics(): WorkflowStatisticsView
    {
        return $this->statistics;
    }
}
