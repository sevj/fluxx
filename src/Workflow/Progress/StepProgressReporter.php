<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Progress;

use Closure;

/**
 * Default reporter: bound to the running step by the runtime, it throttles
 * writes so that a step reporting every batch adds no noticeable load.
 */
final class StepProgressReporter implements StepProgressReporterInterface
{
    private const MIN_INTERVAL_SECONDS = 1.0;

    private ?string $runId = null;
    private ?string $stepCode = null;
    private ?float $lastWriteAt = null;
    private ?string $lastLabel = null;

    /** @var Closure(): float */
    private Closure $clock;

    /**
     * @param (Closure(): float)|null $clock monotonic time in seconds
     */
    public function __construct(
        private readonly StepProgressStoreInterface $store,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
    }

    public function start(string $runId, string $stepCode): void
    {
        $this->runId = $runId;
        $this->stepCode = $stepCode;
        $this->lastWriteAt = null;
        $this->lastLabel = null;
    }

    public function finish(): void
    {
        if ($this->runId !== null && $this->stepCode !== null) {
            $this->store->clear($this->runId, $this->stepCode);
        }

        $this->runId = null;
        $this->stepCode = null;
    }

    public function report(int $done, int $total, string $label = ''): void
    {
        if ($this->runId === null || $this->stepCode === null) {
            return;
        }

        $now = ($this->clock)();

        if (
            $this->lastWriteAt !== null
            && $label === $this->lastLabel
            && $now - $this->lastWriteAt < self::MIN_INTERVAL_SECONDS
        ) {
            return;
        }

        $this->lastWriteAt = $now;
        $this->lastLabel = $label;
        $this->store->save($this->runId, $this->stepCode, new StepProgress($done, $total, $label));
    }
}
