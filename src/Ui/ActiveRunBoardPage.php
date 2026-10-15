<?php

declare(strict_types=1);

namespace Fluxx\Ui;

use DateTimeImmutable;

final readonly class ActiveRunBoardPage
{
    public function __construct(
        private DateTimeImmutable $refreshedAt,
        private array $rows,
        private array $activities,
        private int $activeRunCount,
        private int $runningStepCount,
        private int $busyWorkerCount,
    ) {
    }

    public function refreshedAt(): DateTimeImmutable
    {
        return $this->refreshedAt;
    }

    /**
     * @return list<ActiveRunRowView>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * @return list<ActiveRunActivityView>
     */
    public function activities(): array
    {
        return $this->activities;
    }

    public function activeRunCount(): int
    {
        return $this->activeRunCount;
    }

    public function runningStepCount(): int
    {
        return $this->runningStepCount;
    }

    public function busyWorkerCount(): int
    {
        return $this->busyWorkerCount;
    }
}
