<?php

declare(strict_types=1);

namespace Fluxx\Ui;

use DateTimeImmutable;

final readonly class StepRunDetailView
{
    /**
     * @param array<string, mixed> $metadata
     * @param list<WorkflowPayloadView> $inputPayloads
     * @param list<WorkflowPayloadView> $outputPayloads
     */
    public function __construct(
        private string $workflowCode,
        private string $workflowName,
        private string $workflowSourceSystem,
        private string $workflowTargetSystem,
        private string $runId,
        private string $workflowStatus,
        private ?string $workflowLockKey,
        private ?string $workflowLockScope,
        private ?string $workflowRelaunchMode,
        private ?string $workflowOriginalRunId,
        private ?string $workflowRestartStepCode,
        private string $trigger,
        private string $stepType,
        private string $stepTypeLabel,
        private string $stepTypeTone,
        private ?string $stepTypeToneStyle,
        private string $stepCode,
        private string $stepName,
        private string $stepStatus,
        private int $processedCount,
        private int $successCount,
        private int $errorCount,
        private ?int $durationMs,
        private ?int $memoryPeakBytes,
        private int $retryCount,
        private ?DateTimeImmutable $lastRetryAt,
        private ?DateTimeImmutable $nextRetryAt,
        private DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $startedAt,
        private ?DateTimeImmutable $finishedAt,
        private ?string $errorMessage,
        private ?string $errorCategory,
        private ?string $errorCode,
        private ?string $idempotenceKey,
        private string $deduplicationStatus,
        private ?string $deduplicatedFromRunId,
        private ?string $deduplicatedFromStepCode,
        private array $metadata,
        private array $inputPayloads,
        private array $outputPayloads,
    ) {
    }

    public function workflowCode(): string { return $this->workflowCode; }
    public function workflowName(): string { return $this->workflowName; }
    public function workflowSourceSystem(): string { return $this->workflowSourceSystem; }
    public function workflowTargetSystem(): string { return $this->workflowTargetSystem; }
    public function runId(): string { return $this->runId; }
    public function workflowStatus(): string { return $this->workflowStatus; }
    public function workflowLockKey(): ?string { return $this->workflowLockKey; }
    public function workflowLockScope(): ?string { return $this->workflowLockScope; }
    public function workflowRelaunchMode(): ?string { return $this->workflowRelaunchMode; }
    public function workflowOriginalRunId(): ?string { return $this->workflowOriginalRunId; }
    public function workflowRestartStepCode(): ?string { return $this->workflowRestartStepCode; }
    public function trigger(): string { return $this->trigger; }
    public function stepType(): string { return $this->stepType; }
    public function stepTypeLabel(): string { return $this->stepTypeLabel; }
    public function stepTypeTone(): string { return $this->stepTypeTone; }
    public function stepTypeToneStyle(): ?string { return $this->stepTypeToneStyle; }
    public function stepCode(): string { return $this->stepCode; }
    public function stepName(): string { return $this->stepName; }
    public function stepStatus(): string { return $this->stepStatus; }
    public function processedCount(): int { return $this->processedCount; }
    public function successCount(): int { return $this->successCount; }
    public function errorCount(): int { return $this->errorCount; }
    public function durationMs(): ?int { return $this->durationMs; }
    public function memoryPeakBytes(): ?int { return $this->memoryPeakBytes; }
    public function retryCount(): int { return $this->retryCount; }
    public function lastRetryAt(): ?DateTimeImmutable { return $this->lastRetryAt; }
    public function nextRetryAt(): ?DateTimeImmutable { return $this->nextRetryAt; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function startedAt(): ?DateTimeImmutable { return $this->startedAt; }
    public function finishedAt(): ?DateTimeImmutable { return $this->finishedAt; }
    public function errorMessage(): ?string { return $this->errorMessage; }
    public function errorCategory(): ?string { return $this->errorCategory; }
    public function errorCode(): ?string { return $this->errorCode; }
    public function idempotenceKey(): ?string { return $this->idempotenceKey; }
    public function deduplicationStatus(): string { return $this->deduplicationStatus; }
    public function deduplicatedFromRunId(): ?string { return $this->deduplicatedFromRunId; }
    public function deduplicatedFromStepCode(): ?string { return $this->deduplicatedFromStepCode; }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    /**
     * @return list<WorkflowPayloadView>
     */
    public function inputPayloads(): array
    {
        return $this->inputPayloads;
    }

    /**
     * @return list<WorkflowPayloadView>
     */
    public function outputPayloads(): array
    {
        return $this->outputPayloads;
    }
}
