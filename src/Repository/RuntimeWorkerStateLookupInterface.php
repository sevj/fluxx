<?php

declare(strict_types=1);

namespace Fluxx\Repository;

use DateTimeImmutable;

interface RuntimeWorkerStateLookupInterface
{
    public function hasActiveWorkerForRun(string $runId, DateTimeImmutable $heartbeatThreshold): bool;
}
