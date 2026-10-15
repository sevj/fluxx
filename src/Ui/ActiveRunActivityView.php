<?php

declare(strict_types=1);

namespace Fluxx\Ui;

use DateTimeImmutable;

final readonly class ActiveRunActivityView
{
    public function __construct(
        private DateTimeImmutable $occurredAt,
        private string $kind,
        private string $stepCode,
        private string $stepName,
        private string $runId,
        private string $workflowCode,
        private ?string $workflowName,
        private string $typeTone,
        private ?string $typeToneStyle,
        private ?string $errorMessage,
    ) {
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function stepCode(): string
    {
        return $this->stepCode;
    }

    public function stepName(): string
    {
        return $this->stepName;
    }

    public function runId(): string
    {
        return $this->runId;
    }

    public function workflowName(): ?string
    {
        return $this->workflowName;
    }

    public function workflowCode(): string
    {
        return $this->workflowCode;
    }

    public function typeTone(): string
    {
        return $this->typeTone;
    }

    public function typeToneStyle(): ?string
    {
        return $this->typeToneStyle;
    }

    public function errorMessage(): ?string
    {
        return $this->errorMessage;
    }
}
