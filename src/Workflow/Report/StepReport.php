<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Report;

/**
 * What a step declares about the objects it handled and the anomalies it found.
 * One report describes one object label; the stored format is versioned by the "v" key.
 */
final readonly class StepReport
{
    private const FORMAT_VERSION = 1;

    /**
     * @param list<Anomaly> $anomalies
     */
    private function __construct(
        private ObjectStats $objects,
        private array $anomalies = [],
    ) {
    }

    public static function objects(string $label): self
    {
        return new self(new ObjectStats($label));
    }

    public function read(int $count): self
    {
        return $this->withObjects(read: $count);
    }

    public function created(int $count): self
    {
        return $this->withObjects(created: $count);
    }

    public function updated(int $count): self
    {
        return $this->withObjects(updated: $count);
    }

    public function excluded(string $reason, int $count): self
    {
        $excluded = $this->objects->excluded;
        $excluded[$reason] = ($excluded[$reason] ?? 0) + $count;

        return $this->withObjects(excluded: $excluded);
    }

    /**
     * Replaces the whole list of rejected objects.
     *
     * @param list<array{key: string, message: string}> $rejected
     */
    public function rejected(array $rejected): self
    {
        return $this->withObjects(rejected: $rejected);
    }

    /**
     * @param array<string, int|string> $lines
     */
    public function anomaly(string $title, array $lines, Severity $severity = Severity::Warning): self
    {
        return new self($this->objects, [...$this->anomalies, new Anomaly($title, $lines, $severity)]);
    }

    /**
     * @return array{v: int, objects: array<string, mixed>, anomalies: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'v' => self::FORMAT_VERSION,
            'objects' => $this->objects->toArray(),
            'anomalies' => array_map(static fn (Anomaly $anomaly): array => $anomaly->toArray(), $this->anomalies),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (($data['v'] ?? null) !== self::FORMAT_VERSION) {
            throw new \InvalidArgumentException('Unsupported step report version.');
        }

        if (!is_array($data['objects'] ?? null)) {
            throw new \InvalidArgumentException('Step report without objects.');
        }

        $anomalies = $data['anomalies'] ?? [];

        if (!is_array($anomalies)) {
            throw new \InvalidArgumentException('Step report anomalies must be a list.');
        }

        return new self(
            ObjectStats::fromArray($data['objects']),
            array_map(static fn (mixed $anomaly): Anomaly => Anomaly::fromArray(is_array($anomaly) ? $anomaly : throw new \InvalidArgumentException('Invalid anomaly.')), array_values($anomalies)),
        );
    }

    /**
     * @param array<string, int>|null $excluded
     * @param list<array{key: string, message: string}>|null $rejected
     */
    private function withObjects(?int $read = null, ?int $created = null, ?int $updated = null, ?array $excluded = null, ?array $rejected = null): self
    {
        return new self(
            new ObjectStats(
                $this->objects->label,
                $read ?? $this->objects->read,
                $created ?? $this->objects->created,
                $updated ?? $this->objects->updated,
                $excluded ?? $this->objects->excluded,
                $rejected ?? $this->objects->rejected,
            ),
            $this->anomalies,
        );
    }
}
