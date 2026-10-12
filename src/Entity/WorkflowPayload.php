<?php

declare(strict_types=1);

namespace Fluxx\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Fluxx\Repository\WorkflowPayloadRepository;

#[ORM\Entity(repositoryClass: WorkflowPayloadRepository::class)]
#[ORM\Table(name: 'fluxx_workflow_payload')]
class WorkflowPayload
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: WorkflowRun::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private WorkflowRun $workflowRun;

    #[ORM\ManyToOne(targetEntity: WorkflowStepRun::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private WorkflowStepRun $sourceStepRun;

    #[ORM\ManyToOne(targetEntity: WorkflowPayloadContent::class, cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private WorkflowPayloadContent $payloadContent;

    #[ORM\Column(length: 50)]
    private string $targetStepType;

    #[ORM\Column(length: 180)]
    private string $targetStepName;

    #[ORM\Column]
    private int $sequence;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $metadata;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        WorkflowRun $workflowRun,
        WorkflowStepRun $sourceStepRun,
        WorkflowPayloadContent $payloadContent,
        string $targetStepType,
        string $targetStepName,
        int $sequence,
        array $metadata = [],
    ) {
        $this->workflowRun = $workflowRun;
        $this->sourceStepRun = $sourceStepRun;
        $this->payloadContent = $payloadContent;
        $this->targetStepType = $targetStepType;
        $this->targetStepName = $targetStepName;
        $this->sequence = $sequence;
        $this->metadata = $metadata;
        $this->createdAt = new DateTimeImmutable();
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function workflowRun(): WorkflowRun
    {
        return $this->workflowRun;
    }

    public function sourceStepRun(): WorkflowStepRun
    {
        return $this->sourceStepRun;
    }

    public function payloadContent(): WorkflowPayloadContent
    {
        return $this->payloadContent;
    }

    public function targetStepType(): string
    {
        return $this->targetStepType;
    }

    public function targetStepName(): string
    {
        return $this->targetStepName;
    }

    public function sequence(): int
    {
        return $this->sequence;
    }

    public function format(): string
    {
        return $this->payloadContent->format();
    }

    public function compression(): string
    {
        return $this->payloadContent->compression();
    }

    public function storageMode(): string
    {
        return $this->payloadContent->storageMode();
    }

    public function content(): string
    {
        return $this->payloadContent->content();
    }

    public function contentHash(): string
    {
        return $this->payloadContent->contentHash();
    }

    public function recordCount(): int
    {
        return $this->payloadContent->recordCount();
    }

    public function rawSize(): int
    {
        return $this->payloadContent->rawSize();
    }

    public function storedSize(): int
    {
        return $this->payloadContent->storedSize();
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
}
