<?php

declare(strict_types=1);

namespace Fluxx\Repository;

use Fluxx\Entity\WorkflowRun;

interface WorkflowRunLookupInterface
{
    public function findOneByRunId(string $runId): ?WorkflowRun;

    /**
     * @return array{status: string, metadata: array<string, mixed>, finishedAt: ?\DateTimeImmutable}|null
     */
    public function findPersistedRunStateByRunId(string $runId): ?array;
}
