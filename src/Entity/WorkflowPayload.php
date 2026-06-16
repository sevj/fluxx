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

    #[ORM\Column(length: 50)]
    private string $targetStepType;

    #[ORM\Column(length: 180)]
    private string $targetStepName;

    #[ORM\Column]
    private int $sequence;

    #[ORM\Column(length: 20)]
    private string $format;

    #[ORM\Column(length: 20)]
    private string $compression;

    #[ORM\Column(length: 20)]
    private string $storageMode;

    #[ORM\Column(type: 'text')]
    private string $content;

    #[ORM\Column(length: 64)]
    private string $contentHash;

    #[ORM\Column(options: ['default' => 0])]
    private int $recordCount = 0;

    #[ORM\Column]
    private int $rawSize;

    #[ORM\Column]
    private int $storedSize;

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
        string $targetStepType,
        string $targetStepName,
        int $sequence,
        string $format,
        string $compression,
        string $storageMode,
        string $content,
        string $contentHash,
        int $recordCount,
        int $rawSize,
        int $storedSize,
        array $metadata = [],
    ) {
        $this->workflowRun = $workflowRun;
        $this->sourceStepRun = $sourceStepRun;
        $this->targetStepType = $targetStepType;
        $this->targetStepName = $targetStepName;
        $this->sequence = $sequence;
        $this->format = $format;
        $this->compression = $compression;
        $this->storageMode = $storageMode;
        $this->content = $content;
        $this->contentHash = $contentHash;
        $this->recordCount = $recordCount;
        $this->rawSize = $rawSize;
        $this->storedSize = $storedSize;
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
        return $this->format;
    }

    public function compression(): string
    {
        return $this->compression;
    }

    public function storageMode(): string
    {
        return $this->storageMode;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function contentHash(): string
    {
        return $this->contentHash;
    }

    public function recordCount(): int
    {
        return $this->recordCount;
    }

    public function rawSize(): int
    {
        return $this->rawSize;
    }

    public function storedSize(): int
    {
        return $this->storedSize;
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
