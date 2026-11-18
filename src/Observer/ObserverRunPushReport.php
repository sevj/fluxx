<?php

declare(strict_types=1);

namespace Fluxx\Observer;

use DateTimeImmutable;

/**
 * Outcome of one push of run reports to the observer.
 */
final readonly class ObserverRunPushReport
{
    /**
     * @param list<array{runId: string, error: string}> $rejectedRuns runs the observer refused
     */
    public function __construct(
        public DateTimeImmutable $since,
        public int $pushedRunCount,
        public array $rejectedRuns,
    ) {
    }
}
