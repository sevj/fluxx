<?php

declare(strict_types=1);

namespace Fluxx\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Fluxx\Repository\WorkflowRunReportRepository;

/**
 * The report of a completed run. It outlives the pruning of payloads and is deleted with its run.
 */
#[ORM\Entity(repositoryClass: WorkflowRunReportRepository::class)]
#[ORM\Table(name: 'fluxx_workflow_run_report')]
#[ORM\UniqueConstraint(name: 'uniq_fluxx_workflow_run_report_run', columns: ['workflow_run_id'])]
class WorkflowRunReport
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: WorkflowRun::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private WorkflowRun $workflowRun;

    #[ORM\Column]
    private int $formatVersion;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $content;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $builtAt;

    /**
     * @param array<string, mixed> $content
     */
    public function __construct(WorkflowRun $workflowRun, int $formatVersion, array $content, ?DateTimeImmutable $builtAt = null)
    {
        $this->workflowRun = $workflowRun;
        $this->formatVersion = $formatVersion;
        $this->content = $content;
        $this->builtAt = $builtAt ?? new DateTimeImmutable();
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function workflowRun(): WorkflowRun
    {
        return $this->workflowRun;
    }

    public function formatVersion(): int
    {
        return $this->formatVersion;
    }

    /**
     * @return array<string, mixed>
     */
    public function content(): array
    {
        return $this->content;
    }

    public function builtAt(): DateTimeImmutable
    {
        return $this->builtAt;
    }

    /**
     * @param array<string, mixed> $content
     */
    public function replace(int $formatVersion, array $content, ?DateTimeImmutable $builtAt = null): void
    {
        $this->formatVersion = $formatVersion;
        $this->content = $content;
        $this->builtAt = $builtAt ?? new DateTimeImmutable();
    }
}
