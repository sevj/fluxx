<?php

declare(strict_types=1);

namespace Fluxx\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Fluxx\Repository\RuntimeWorkerStateRepository;

#[ORM\Entity(repositoryClass: RuntimeWorkerStateRepository::class)]
#[ORM\Table(name: 'fluxx_runtime_worker_state')]
#[ORM\UniqueConstraint(name: 'uniq_fluxx_runtime_worker_state_worker_name', columns: ['worker_name'])]
#[ORM\Index(name: 'idx_fluxx_runtime_worker_state_transport_name', columns: ['transport_name'])]
#[ORM\Index(name: 'idx_fluxx_runtime_worker_state_last_heartbeat_at', columns: ['last_heartbeat_at'])]
class RuntimeWorkerState
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 190)]
    private string $workerName;

    #[ORM\Column(length: 64)]
    private string $transportName;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $receiverName = null;

    #[ORM\Column(length: 180)]
    private string $host;

    #[ORM\Column]
    private int $pid;

    #[ORM\Column(length: 32)]
    private string $status;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $currentMessageClass = null;

    #[ORM\Column(length: 190, nullable: true)]
    private ?string $currentTransportMessageId = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $workflowCode = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $runId = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $stepCode = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $startedProcessingAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $lastHeartbeatAt;

    #[ORM\Column(nullable: true)]
    private ?int $memoryBytes = null;

    public function __construct(
        string $workerName,
        string $transportName,
        string $host,
        int $pid,
        ?string $receiverName = null,
    ) {
        $this->workerName = $workerName;
        $this->transportName = $transportName;
        $this->host = $host;
        $this->pid = $pid;
        $this->receiverName = $receiverName;
        $this->status = 'idle';
        $this->lastHeartbeatAt = new DateTimeImmutable();
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function workerName(): string
    {
        return $this->workerName;
    }

    public function transportName(): string
    {
        return $this->transportName;
    }

    public function receiverName(): ?string
    {
        return $this->receiverName;
    }

    public function host(): string
    {
        return $this->host;
    }

    public function pid(): int
    {
        return $this->pid;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function currentMessageClass(): ?string
    {
        return $this->currentMessageClass;
    }

    public function currentTransportMessageId(): ?string
    {
        return $this->currentTransportMessageId;
    }

    public function workflowCode(): ?string
    {
        return $this->workflowCode;
    }

    public function runId(): ?string
    {
        return $this->runId;
    }

    public function stepCode(): ?string
    {
        return $this->stepCode;
    }

    public function startedProcessingAt(): ?DateTimeImmutable
    {
        return $this->startedProcessingAt;
    }

    public function lastHeartbeatAt(): DateTimeImmutable
    {
        return $this->lastHeartbeatAt;
    }

    public function memoryBytes(): ?int
    {
        return $this->memoryBytes;
    }

    public function syncIdentity(
        string $transportName,
        string $host,
        int $pid,
        ?string $receiverName = null,
    ): void {
        $this->transportName = $transportName;
        $this->host = $host;
        $this->pid = $pid;
        $this->receiverName = $receiverName;
    }

    public function touch(?DateTimeImmutable $heartbeatAt = null, ?int $memoryBytes = null): void
    {
        $this->lastHeartbeatAt = $heartbeatAt ?? new DateTimeImmutable();
        $this->memoryBytes = $memoryBytes;
    }

    public function markIdle(?DateTimeImmutable $heartbeatAt = null, ?int $memoryBytes = null): void
    {
        $this->status = 'idle';
        $this->clearCurrentMessage();
        $this->touch($heartbeatAt, $memoryBytes);
    }

    public function markProcessing(
        string $messageClass,
        ?string $transportMessageId,
        ?string $workflowCode,
        ?string $runId,
        ?string $stepCode,
        ?DateTimeImmutable $startedAt = null,
        ?int $memoryBytes = null,
    ): void {
        $this->status = 'processing';
        $this->currentMessageClass = $messageClass;
        $this->currentTransportMessageId = $transportMessageId;
        $this->workflowCode = $workflowCode;
        $this->runId = $runId;
        $this->stepCode = $stepCode;
        $this->startedProcessingAt = $startedAt ?? new DateTimeImmutable();
        $this->touch($this->startedProcessingAt, $memoryBytes);
    }

    public function markStopped(?DateTimeImmutable $heartbeatAt = null, ?int $memoryBytes = null): void
    {
        $this->status = 'stopped';
        $this->clearCurrentMessage();
        $this->touch($heartbeatAt, $memoryBytes);
    }

    private function clearCurrentMessage(): void
    {
        $this->currentMessageClass = null;
        $this->currentTransportMessageId = null;
        $this->workflowCode = null;
        $this->runId = null;
        $this->stepCode = null;
        $this->startedProcessingAt = null;
    }
}
