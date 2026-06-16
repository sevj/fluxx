<?php

declare(strict_types=1);

namespace Fluxx\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Fluxx\Entity\Enum\WorkflowStepDeduplicationStatus;
use Fluxx\Entity\Enum\WorkflowStepRunStatus;
use Fluxx\Repository\WorkflowStepRunRepository;

#[ORM\Entity(repositoryClass: WorkflowStepRunRepository::class)]
#[ORM\Table(name: 'fluxx_workflow_step_run')]
#[ORM\Index(name: 'idx_fluxx_workflow_step_run_idempotence_key', columns: ['step_name', 'idempotence_key'])]
#[ORM\Index(name: 'idx_fluxx_workflow_step_run_dedup_from', columns: ['deduplicated_from_step_run_id'])]
class WorkflowStepRun
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: WorkflowRun::class, inversedBy: 'stepRuns')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private WorkflowRun $workflowRun;

    #[ORM\Column(length: 50)]
    private string $stepType;

    #[ORM\Column(length: 180)]
    private string $stepName;

    #[ORM\Column]
    private int $position;

    #[ORM\Column(enumType: WorkflowStepRunStatus::class, length: 30)]
    private WorkflowStepRunStatus $status;

    #[ORM\Column(length: 190, nullable: true)]
    private ?string $idempotenceKey = null;

    #[ORM\Column(enumType: WorkflowStepDeduplicationStatus::class, length: 20)]
    private WorkflowStepDeduplicationStatus $deduplicationStatus;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?self $deduplicatedFromStepRun = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $processedCount = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $successCount = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $errorCount = 0;

    #[ORM\Column(nullable: true)]
    private ?int $durationMs = null;

    #[ORM\Column(nullable: true)]
    private ?int $memoryPeakBytes = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $retryCount = 0;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $lastRetryAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $nextRetryAt = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $metadata;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $finishedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        WorkflowRun $workflowRun,
        string $stepType,
        string $stepName,
        int $position,
        array $metadata = [],
    ) {
        $this->workflowRun = $workflowRun;
        $this->stepType = $stepType;
        $this->stepName = $stepName;
        $this->position = $position;
        $this->metadata = $metadata;
        $this->status = WorkflowStepRunStatus::Pending;
        $this->deduplicationStatus = WorkflowStepDeduplicationStatus::None;
        $this->createdAt = new DateTimeImmutable();

        $this->workflowRun->addStepRun($this);
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function workflowRun(): WorkflowRun
    {
        return $this->workflowRun;
    }

    public function stepType(): string
    {
        return $this->stepType;
    }

    public function stepName(): string
    {
        return $this->stepName;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function status(): WorkflowStepRunStatus
    {
        return $this->status;
    }

    public function idempotenceKey(): ?string
    {
        return $this->idempotenceKey;
    }

    public function deduplicationStatus(): WorkflowStepDeduplicationStatus
    {
        return $this->deduplicationStatus;
    }

    public function deduplicatedFromStepRun(): ?self
    {
        return $this->deduplicatedFromStepRun;
    }

    public function processedCount(): int
    {
        return $this->processedCount;
    }

    public function successCount(): int
    {
        return $this->successCount;
    }

    public function errorCount(): int
    {
        return $this->errorCount;
    }

    public function durationMs(): ?int
    {
        return $this->durationMs;
    }

    public function memoryPeakBytes(): ?int
    {
        return $this->memoryPeakBytes;
    }

    public function retryCount(): int
    {
        return $this->retryCount;
    }

    public function lastRetryAt(): ?DateTimeImmutable
    {
        return $this->lastRetryAt;
    }

    public function nextRetryAt(): ?DateTimeImmutable
    {
        return $this->nextRetryAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function startedAt(): ?DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function finishedAt(): ?DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function errorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function errorPayload(): ?array
    {
        $errorPayload = $this->metadata['error'] ?? null;

        return is_array($errorPayload) ? $errorPayload : null;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function replaceMetadata(array $metadata): void
    {
        $this->metadata = $metadata;
    }

    public function attachToRun(WorkflowRun $workflowRun): void
    {
        $this->workflowRun = $workflowRun;
    }

    public function markRunning(?DateTimeImmutable $startedAt = null): void
    {
        $this->status = WorkflowStepRunStatus::Running;
        $this->startedAt ??= $startedAt ?? new DateTimeImmutable();
        $this->finishedAt = null;
        $this->errorMessage = null;
        $this->durationMs = null;
        $this->memoryPeakBytes = null;
        $this->nextRetryAt = null;
        $this->clearErrorPayload();
    }

    public function markRelaunched(?DateTimeImmutable $startedAt = null): void
    {
        $this->status = WorkflowStepRunStatus::Relaunched;
        $this->startedAt = $startedAt;
        $this->finishedAt = null;
        $this->errorMessage = null;
        $this->durationMs = null;
        $this->memoryPeakBytes = null;
        $this->nextRetryAt = null;
        $this->clearErrorPayload();
    }

    /**
     * @param array<string, mixed>|null $errorPayload
     */
    public function scheduleRetry(
        DateTimeImmutable $lastRetryAt,
        DateTimeImmutable $nextRetryAt,
        ?string $errorMessage = null,
        int $errorCount = 0,
        ?int $durationMs = null,
        ?int $memoryPeakBytes = null,
        ?array $errorPayload = null,
    ): void {
        ++$this->retryCount;
        $this->lastRetryAt = $lastRetryAt;
        $this->nextRetryAt = $nextRetryAt;
        $this->status = WorkflowStepRunStatus::Retrying;
        $this->finishedAt = null;
        $this->errorCount = $errorCount;
        $this->durationMs = $durationMs;
        $this->memoryPeakBytes = $memoryPeakBytes;
        $this->errorMessage = $errorMessage;
        $this->storeErrorPayload($errorPayload);
    }

    public function markIdempotenceApplied(string $idempotenceKey): void
    {
        $this->idempotenceKey = $idempotenceKey;
        $this->deduplicationStatus = WorkflowStepDeduplicationStatus::Applied;
        $this->deduplicatedFromStepRun = null;
    }

    public function markDeduplicated(string $idempotenceKey, self $sourceStepRun): void
    {
        $this->idempotenceKey = $idempotenceKey;
        $this->deduplicationStatus = WorkflowStepDeduplicationStatus::Deduplicated;
        $this->deduplicatedFromStepRun = $sourceStepRun;
    }

    public function markCompleted(
        int $processedCount,
        int $successCount,
        int $errorCount = 0,
        ?int $durationMs = null,
        ?int $memoryPeakBytes = null,
        ?DateTimeImmutable $finishedAt = null,
    ): void {
        $this->status = WorkflowStepRunStatus::Completed;
        $this->processedCount = $processedCount;
        $this->successCount = $successCount;
        $this->errorCount = $errorCount;
        $this->startedAt ??= new DateTimeImmutable();
        $this->finishedAt = $finishedAt ?? new DateTimeImmutable();
        $this->durationMs = $durationMs;
        $this->memoryPeakBytes = $memoryPeakBytes;
        $this->errorMessage = null;
        $this->clearErrorPayload();
    }

    /**
     * @param array<string, mixed>|null $errorPayload
     */
    public function markFailed(
        ?string $errorMessage = null,
        int $processedCount = 0,
        int $successCount = 0,
        int $errorCount = 0,
        ?int $durationMs = null,
        ?int $memoryPeakBytes = null,
        ?DateTimeImmutable $finishedAt = null,
        ?array $errorPayload = null,
    ): void {
        $this->status = WorkflowStepRunStatus::Failed;
        $this->processedCount = $processedCount;
        $this->successCount = $successCount;
        $this->errorCount = $errorCount;
        $this->startedAt ??= new DateTimeImmutable();
        $this->finishedAt = $finishedAt ?? new DateTimeImmutable();
        $this->durationMs = $durationMs;
        $this->memoryPeakBytes = $memoryPeakBytes;
        $this->errorMessage = $errorMessage;
        $this->storeErrorPayload($errorPayload);
    }

    /**
     * @param array<string, mixed>|null $errorPayload
     */
    private function storeErrorPayload(?array $errorPayload): void
    {
        if ($errorPayload === null) {
            return;
        }

        $this->metadata['error'] = $errorPayload;
    }

    private function clearErrorPayload(): void
    {
        unset($this->metadata['error']);
    }
}
