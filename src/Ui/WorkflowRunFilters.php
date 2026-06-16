<?php

declare(strict_types=1);

namespace Fluxx\Ui;

use DateTimeImmutable;

final readonly class WorkflowRunFilters
{
    public function __construct(
        private string $searchQuery = '',
        private ?string $workflowCode = null,
        private ?string $status = null,
        private ?string $sourceSystem = null,
        private ?string $targetSystem = null,
        private string $errorPresence = 'all',
        private ?DateTimeImmutable $dateFrom = null,
        private ?DateTimeImmutable $dateTo = null,
    ) {
    }

    public function searchQuery(): string
    {
        return $this->searchQuery;
    }

    public function workflowCode(): ?string
    {
        return $this->workflowCode;
    }

    public function status(): ?string
    {
        return $this->status;
    }

    public function sourceSystem(): ?string
    {
        return $this->sourceSystem;
    }

    public function targetSystem(): ?string
    {
        return $this->targetSystem;
    }

    public function errorPresence(): string
    {
        return $this->errorPresence;
    }

    public function dateFrom(): ?DateTimeImmutable
    {
        return $this->dateFrom;
    }

    public function dateTo(): ?DateTimeImmutable
    {
        return $this->dateTo;
    }

    public function hasActiveFilters(): bool
    {
        return $this->searchQuery !== ''
            || $this->status !== null
            || $this->sourceSystem !== null
            || $this->targetSystem !== null
            || $this->errorPresence !== 'all'
            || $this->dateFrom !== null
            || $this->dateTo !== null;
    }

    public function withErrorPresence(string $errorPresence): self
    {
        return new self(
            searchQuery: $this->searchQuery,
            workflowCode: $this->workflowCode,
            status: $this->status,
            sourceSystem: $this->sourceSystem,
            targetSystem: $this->targetSystem,
            errorPresence: $errorPresence,
            dateFrom: $this->dateFrom,
            dateTo: $this->dateTo,
        );
    }
}
