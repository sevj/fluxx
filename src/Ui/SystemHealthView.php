<?php

declare(strict_types=1);

namespace Fluxx\Ui;

use DateTimeImmutable;

final readonly class SystemHealthView
{
    /**
     * @param list<SystemHealthFactView> $facts
     * @param list<SystemHealthMetricView> $metrics
     * @param list<SystemHealthCheckView> $checks
     * @param list<SystemHealthFindingView> $findings
     * @param list<array<string, mixed>> $workers
     * @param list<array<string, mixed>> $activeLocks
     */
    public function __construct(
        private bool $ok,
        private string $overallState,
        private DateTimeImmutable $refreshedAt,
        private array $summary,
        private array $facts,
        private array $metrics,
        private array $checks,
        private array $findings,
        private array $workers,
        private array $activeLocks,
    ) {
    }

    public function ok(): bool
    {
        return $this->ok;
    }

    public function overallState(): string
    {
        return $this->overallState;
    }

    public function refreshedAt(): DateTimeImmutable
    {
        return $this->refreshedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return $this->summary;
    }

    /**
     * @return list<SystemHealthFactView>
     */
    public function facts(): array
    {
        return $this->facts;
    }

    /**
     * @return list<SystemHealthMetricView>
     */
    public function metrics(): array
    {
        return $this->metrics;
    }

    /**
     * @return list<SystemHealthCheckView>
     */
    public function checks(): array
    {
        return $this->checks;
    }

    /**
     * @return list<SystemHealthFindingView>
     */
    public function findings(): array
    {
        return $this->findings;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function workers(): array
    {
        return $this->workers;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function activeLocks(): array
    {
        return $this->activeLocks;
    }
}
