<?php

declare(strict_types=1);

namespace Fluxx\Observer;

/**
 * What the observer did with a batch of run reports.
 */
final readonly class ObserverIngestResult
{
    /**
     * @param list<array{index: int, error: string}> $rejections reports the observer refused, by their index in the batch
     */
    public function __construct(
        public int $accepted,
        public array $rejections,
    ) {
    }
}
