<?php

declare(strict_types=1);

namespace Fluxx\Repository;

use Fluxx\Entity\WorkflowRun;

interface WorkflowRunLookupInterface
{
    public function findOneByRunId(string $runId): ?WorkflowRun;
}
