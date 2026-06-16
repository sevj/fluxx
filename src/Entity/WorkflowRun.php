<?php

declare(strict_types=1);

namespace Fluxx\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Fluxx\Entity\Enum\WorkflowRunStatus;
use Fluxx\Entity\Enum\WorkflowExecutionLockScope;
use Fluxx\Repository\WorkflowRunRepository;

#[ORM\Entity(repositoryClass: WorkflowRunRepository::class)]
#[ORM\Table(name: 'fluxx_workflow_run')]
#[ORM\UniqueConstraint(name: 'uniq_fluxx_workflow_run_run_id', columns: ['run_id'])]
#[ORM\Index(name: 'idx_fluxx_workflow_run_lock_key', columns: ['lock_key'])]
class WorkflowRun
{
    /**
     * @var Collection<int, WorkflowStepRun>
     */
    #[ORM\OneToMany(mappedBy: 'workflowRun', targetEntity: WorkflowStepRun::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $stepRuns;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $runId;

    #[ORM\Column(length: 180)]
    private string $workflowName;

    #[ORM\Column(length: 180)]
    private string $sourceSystem;

    #[ORM\Column(length: 180)]
    private string $targetSystem;

    #[ORM\Column(length: 50)]
    private string $trigger;

    #[ORM\Column(enumType: WorkflowRunStatus::class, length: 30)]
    private WorkflowRunStatus $status;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $batchId;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $metadata;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $finishedAt = null;

    #[ORM\Column(length: 190, nullable: true)]
    private ?string $lockKey = null;

    #[ORM\Column(enumType: WorkflowExecutionLockScope::class, length: 40, nullable: true)]
    private ?WorkflowExecutionLockScope $lockScope = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        string $runId,
        string $workflowName,
        string $sourceSystem,
        string $targetSystem,
        string $trigger,
        ?string $batchId = null,
        array $metadata = [],
    ) {
        $this->runId = $runId;
        $this->workflowName = $workflowName;
        $this->sourceSystem = $sourceSystem;
        $this->targetSystem = $targetSystem;
        $this->trigger = $trigger;
        $this->batchId = $batchId;
        $this->metadata = $metadata;
        $this->status = WorkflowRunStatus::Pending;
        $this->createdAt = new DateTimeImmutable();
        $this->stepRuns = new ArrayCollection();
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function runId(): string
    {
        return $this->runId;
    }

    public function workflowName(): string
    {
        return $this->workflowName;
    }

    public function sourceSystem(): string
    {
        return $this->sourceSystem;
    }

    public function targetSystem(): string
    {
        return $this->targetSystem;
    }

    public function trigger(): string
    {
        return $this->trigger;
    }

    public function status(): WorkflowRunStatus
    {
        return $this->status;
    }

    public function batchId(): ?string
    {
        return $this->batchId;
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

    public function lockKey(): ?string
    {
        return $this->lockKey;
    }

    public function lockScope(): ?WorkflowExecutionLockScope
    {
        return $this->lockScope;
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
     * @return array<string, mixed>|null
     */
    public function relaunchMetadata(): ?array
    {
        $relaunch = $this->metadata['relaunch'] ?? null;

        return is_array($relaunch) ? $relaunch : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function cancellationMetadata(): ?array
    {
        $cancellation = $this->metadata['cancellation'] ?? null;

        return is_array($cancellation) ? $cancellation : null;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function replaceMetadata(array $metadata): void
    {
        $this->metadata = $metadata;
    }

    public function attachExecutionLock(string $lockKey, WorkflowExecutionLockScope $lockScope): void
    {
        $this->lockKey = $lockKey;
        $this->lockScope = $lockScope;
    }

    /**
     * @return Collection<int, WorkflowStepRun>
     */
    public function stepRuns(): Collection
    {
        return $this->stepRuns;
    }

    public function addStepRun(WorkflowStepRun $stepRun): void
    {
        if ($this->stepRuns->contains($stepRun)) {
            return;
        }

        $this->stepRuns->add($stepRun);
        $stepRun->attachToRun($this);
    }

    public function markRunning(?DateTimeImmutable $startedAt = null): void
    {
        $this->status = WorkflowRunStatus::Running;
        $this->startedAt ??= $startedAt ?? new DateTimeImmutable();
        $this->finishedAt = null;
        $this->errorMessage = null;
        $this->clearErrorPayload();
    }

    public function markRetrying(?DateTimeImmutable $startedAt = null): void
    {
        $this->status = WorkflowRunStatus::Retrying;
        $this->startedAt ??= $startedAt ?? new DateTimeImmutable();
        $this->finishedAt = null;
        $this->errorMessage = null;
        $this->clearErrorPayload();
    }

    public function markRelaunched(?DateTimeImmutable $startedAt = null): void
    {
        $this->status = WorkflowRunStatus::Relaunched;
        $this->startedAt = $startedAt;
        $this->finishedAt = null;
        $this->errorMessage = null;
        $this->clearErrorPayload();
    }

    public function markCompleted(?DateTimeImmutable $finishedAt = null): void
    {
        $this->status = WorkflowRunStatus::Completed;
        $this->startedAt ??= new DateTimeImmutable();
        $this->finishedAt = $finishedAt ?? new DateTimeImmutable();
        $this->errorMessage = null;
        $this->clearErrorPayload();
    }

    public function markCancelled(?DateTimeImmutable $finishedAt = null): void
    {
        $this->status = WorkflowRunStatus::Cancelled;
        $this->finishedAt = $finishedAt ?? new DateTimeImmutable();
        $this->errorMessage = null;
        $this->clearErrorPayload();
    }

    /**
     * @param array<string, mixed>|null $errorPayload
     */
    public function markFailed(?string $errorMessage = null, ?DateTimeImmutable $finishedAt = null, ?array $errorPayload = null): void
    {
        $this->status = WorkflowRunStatus::Failed;
        $this->finishedAt = $finishedAt ?? new DateTimeImmutable();
        $this->errorMessage = $errorMessage;
        $this->storeErrorPayload($errorPayload);
    }

    /**
     * @param array<string, mixed>|null $errorPayload
     */
    public function markPartiallyFailed(?string $errorMessage = null, ?DateTimeImmutable $finishedAt = null, ?array $errorPayload = null): void
    {
        $this->status = WorkflowRunStatus::PartiallyFailed;
        $this->finishedAt = $finishedAt ?? new DateTimeImmutable();
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
