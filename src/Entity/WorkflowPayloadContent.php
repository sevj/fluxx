<?php

declare(strict_types=1);

namespace Fluxx\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Fluxx\Repository\WorkflowPayloadContentRepository;

#[ORM\Entity(repositoryClass: WorkflowPayloadContentRepository::class)]
#[ORM\Table(name: 'fluxx_workflow_payload_content')]
#[ORM\UniqueConstraint(name: 'uniq_fluxx_payload_content_run_hash', columns: ['workflow_run_id', 'content_hash'])]
class WorkflowPayloadContent
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

    #[ORM\Column(length: 64)]
    private string $contentHash;

    #[ORM\Column(length: 20)]
    private string $format;

    #[ORM\Column(length: 20)]
    private string $compression;

    #[ORM\Column(length: 20)]
    private string $storageMode;

    #[ORM\Column(type: 'text')]
    private string $content;

    #[ORM\Column(options: ['default' => 0])]
    private int $recordCount = 0;

    #[ORM\Column]
    private int $rawSize;

    #[ORM\Column]
    private int $storedSize;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(
        WorkflowRun $workflowRun,
        WorkflowStepRun $sourceStepRun,
        string $contentHash,
        string $format,
        string $compression,
        string $storageMode,
        string $content,
        int $recordCount,
        int $rawSize,
        int $storedSize,
    ) {
        $this->workflowRun = $workflowRun;
        $this->sourceStepRun = $sourceStepRun;
        $this->contentHash = $contentHash;
        $this->format = $format;
        $this->compression = $compression;
        $this->storageMode = $storageMode;
        $this->content = $content;
        $this->recordCount = $recordCount;
        $this->rawSize = $rawSize;
        $this->storedSize = $storedSize;
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

    public function contentHash(): string
    {
        return $this->contentHash;
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

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
