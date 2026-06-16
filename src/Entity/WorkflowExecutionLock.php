<?php

declare(strict_types=1);

namespace Fluxx\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Fluxx\Entity\Enum\WorkflowExecutionLockScope;
use Fluxx\Repository\WorkflowExecutionLockRepository;

#[ORM\Entity(repositoryClass: WorkflowExecutionLockRepository::class)]
#[ORM\Table(name: 'fluxx_workflow_execution_lock')]
#[ORM\Index(name: 'idx_fluxx_workflow_execution_lock_key', columns: ['lock_key'])]
#[ORM\Index(name: 'idx_fluxx_workflow_execution_lock_owner_run_id', columns: ['owner_run_id'])]
class WorkflowExecutionLock
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $workflowName;

    #[ORM\Column(length: 64)]
    private string $ownerRunId;

    #[ORM\Column(length: 190)]
    private string $lockKey;

    #[ORM\Column(enumType: WorkflowExecutionLockScope::class, length: 40)]
    private WorkflowExecutionLockScope $scope;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $businessPartitionKey = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $acquiredAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $releasedAt = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $releaseReason = null;

    public function __construct(
        string $workflowName,
        string $ownerRunId,
        string $lockKey,
        WorkflowExecutionLockScope $scope,
        ?string $businessPartitionKey = null,
    ) {
        $this->workflowName = $workflowName;
        $this->ownerRunId = $ownerRunId;
        $this->lockKey = $lockKey;
        $this->scope = $scope;
        $this->businessPartitionKey = $businessPartitionKey;
        $this->acquiredAt = new DateTimeImmutable();
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function workflowName(): string
    {
        return $this->workflowName;
    }

    public function ownerRunId(): string
    {
        return $this->ownerRunId;
    }

    public function lockKey(): string
    {
        return $this->lockKey;
    }

    public function scope(): WorkflowExecutionLockScope
    {
        return $this->scope;
    }

    public function businessPartitionKey(): ?string
    {
        return $this->businessPartitionKey;
    }

    public function acquiredAt(): DateTimeImmutable
    {
        return $this->acquiredAt;
    }

    public function releasedAt(): ?DateTimeImmutable
    {
        return $this->releasedAt;
    }

    public function releaseReason(): ?string
    {
        return $this->releaseReason;
    }

    public function isActive(): bool
    {
        return $this->releasedAt === null;
    }

    public function release(string $reason, ?DateTimeImmutable $releasedAt = null): void
    {
        if ($this->releasedAt !== null) {
            return;
        }

        $this->releasedAt = $releasedAt ?? new DateTimeImmutable();
        $this->releaseReason = $reason;
    }
}
