<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Progress;

/**
 * Stores live step progress outside of the step's database transaction, which
 * would otherwise hide it from other processes until the step commits.
 */
interface StepProgressStoreInterface
{
    public function save(string $runId, string $stepCode, StepProgress $progress): void;

    public function clear(string $runId, string $stepCode): void;

    /**
     * @return array<string, StepProgress> progress indexed by step code
     */
    public function forRun(string $runId): array;
}
