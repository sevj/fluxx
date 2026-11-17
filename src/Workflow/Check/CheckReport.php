<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Check;

use Fluxx\Workflow\Report\Severity;

/**
 * The controls run by a check step. The stored format is versioned by the "v" key and tagged by "kind",
 * since the step run report column holds either a step report or a check report.
 */
final readonly class CheckReport
{
    public const KIND = 'check';
    private const FORMAT_VERSION = 1;

    /**
     * @param list<CheckResult> $results
     */
    public function __construct(
        private array $results = [],
    ) {
    }

    public function add(CheckResult $result): self
    {
        return new self([...$this->results, $result]);
    }

    /**
     * @return list<CheckResult>
     */
    public function results(): array
    {
        return $this->results;
    }

    /**
     * @return list<CheckResult>
     */
    public function blockingDeviations(): array
    {
        return $this->deviations(Severity::Blocking);
    }

    /**
     * @return list<CheckResult>
     */
    public function warningDeviations(): array
    {
        return $this->deviations(Severity::Warning);
    }

    /**
     * @return array{v: int, kind: string, checks: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'v' => self::FORMAT_VERSION,
            'kind' => self::KIND,
            'checks' => array_map(static fn (CheckResult $result): array => $result->toArray(), $this->results),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (($data['v'] ?? null) !== self::FORMAT_VERSION) {
            throw new \InvalidArgumentException('Unsupported check report version.');
        }

        if (($data['kind'] ?? null) !== self::KIND) {
            throw new \InvalidArgumentException('Not a check report.');
        }

        if (!is_array($data['checks'] ?? null)) {
            throw new \InvalidArgumentException('Check report without checks.');
        }

        return new self(array_map(
            static fn (mixed $check): CheckResult => CheckResult::fromArray(is_array($check) ? $check : throw new \InvalidArgumentException('Invalid check result.')),
            array_values($data['checks']),
        ));
    }

    /**
     * Reads a stored step run report: null when there is none or when it is not a check report.
     *
     * @param array<string, mixed>|null $data
     */
    public static function fromStored(?array $data): ?self
    {
        if ($data === null || ($data['kind'] ?? null) !== self::KIND) {
            return null;
        }

        return self::fromArray($data);
    }

    /**
     * @return list<CheckResult>
     */
    private function deviations(Severity $severity): array
    {
        return array_values(array_filter(
            $this->results,
            static fn (CheckResult $result): bool => $result->isDeviation() && $result->severity === $severity,
        ));
    }
}
