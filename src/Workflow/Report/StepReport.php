<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Report;

/**
 * What a step declares about the objects and links it handled and the anomalies it found.
 * A report holds one or more entries, objects or links, each under its own label: counters apply to the
 * entry declared last. The stored format is versioned by the "v" key and tagged by "kind" (a report stored
 * before the kind existed is a step report).
 */
final readonly class StepReport
{
    public const KIND = 'step';
    private const FORMAT_VERSION = 1;

    /**
     * @param non-empty-list<ObjectStats> $entries
     * @param list<Anomaly> $anomalies
     */
    private function __construct(
        private array $entries,
        private array $anomalies = [],
    ) {
    }

    public static function objects(string $label): self
    {
        return new self([new ObjectStats($label)]);
    }

    /**
     * Associations between objects, reported apart from the objects: created() counts the links
     * written, removed() the ones taken off.
     */
    public static function links(string $label): self
    {
        return new self([new ObjectStats($label, link: true)]);
    }

    /**
     * Starts another object entry in the same report; the counters that follow apply to it.
     */
    public function object(string $label): self
    {
        return new self([...$this->entries, new ObjectStats($label)], $this->anomalies);
    }

    /**
     * Starts another link entry in the same report; the counters that follow apply to it.
     */
    public function link(string $label): self
    {
        return new self([...$this->entries, new ObjectStats($label, link: true)], $this->anomalies);
    }

    public function read(int $count): self
    {
        return $this->withCurrent(read: $count);
    }

    public function created(int $count): self
    {
        return $this->withCurrent(created: $count);
    }

    public function updated(int $count): self
    {
        if ($this->current()->link) {
            throw new \LogicException('Links are created or removed, not updated.');
        }

        return $this->withCurrent(updated: $count);
    }

    public function removed(int $count): self
    {
        if (!$this->current()->link) {
            throw new \LogicException('Only links can be removed: declare them with StepReport::links() or link().');
        }

        return $this->withCurrent(removed: $count);
    }

    public function excluded(string $reason, int $count): self
    {
        $excluded = $this->current()->excluded;
        $excluded[$reason] = ($excluded[$reason] ?? 0) + $count;

        return $this->withCurrent(excluded: $excluded);
    }

    /**
     * Replaces the whole list of rejected records of the current entry.
     *
     * @param list<array{key: string, message: string}> $rejected
     */
    public function rejected(array $rejected): self
    {
        return $this->withCurrent(rejected: $rejected);
    }

    /**
     * @param array<string, int|string> $lines
     */
    public function anomaly(string $title, array $lines, Severity $severity = Severity::Warning): self
    {
        return new self($this->entries, [...$this->anomalies, new Anomaly($title, $lines, $severity)]);
    }

    /**
     * @return non-empty-list<ObjectStats>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * @return list<Anomaly>
     */
    public function anomalies(): array
    {
        return $this->anomalies;
    }

    /**
     * @return array{v: int, kind: string, objects: list<array<string, mixed>>, anomalies: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'v' => self::FORMAT_VERSION,
            'kind' => self::KIND,
            'objects' => array_map(static fn (ObjectStats $entry): array => $entry->toArray(), $this->entries),
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

        if (($data['kind'] ?? self::KIND) !== self::KIND) {
            throw new \InvalidArgumentException('Not a step report.');
        }

        $objects = $data['objects'] ?? null;

        if (!is_array($objects) || $objects === []) {
            throw new \InvalidArgumentException('Step report without objects.');
        }

        if (!array_is_list($objects)) {
            $objects = [$objects];
        }

        $anomalies = $data['anomalies'] ?? [];

        if (!is_array($anomalies)) {
            throw new \InvalidArgumentException('Step report anomalies must be a list.');
        }

        return new self(
            array_map(static fn (mixed $entry): ObjectStats => ObjectStats::fromArray(is_array($entry) ? $entry : throw new \InvalidArgumentException('Invalid step report entry.')), $objects),
            array_map(static fn (mixed $anomaly): Anomaly => Anomaly::fromArray(is_array($anomaly) ? $anomaly : throw new \InvalidArgumentException('Invalid anomaly.')), array_values($anomalies)),
        );
    }

    /**
     * Reads a stored step run report: null when there is none or when it is not a step report.
     *
     * @param array<string, mixed>|null $data
     */
    public static function fromStored(?array $data): ?self
    {
        if ($data === null || ($data['kind'] ?? self::KIND) !== self::KIND) {
            return null;
        }

        return self::fromArray($data);
    }

    private function current(): ObjectStats
    {
        return $this->entries[array_key_last($this->entries)];
    }

    /**
     * @param array<string, int>|null $excluded
     * @param list<array{key: string, message: string}>|null $rejected
     */
    private function withCurrent(?int $read = null, ?int $created = null, ?int $updated = null, ?array $excluded = null, ?array $rejected = null, ?int $removed = null): self
    {
        $current = $this->current();
        $entries = $this->entries;
        $entries[array_key_last($entries)] = new ObjectStats(
            $current->label,
            $read ?? $current->read,
            $created ?? $current->created,
            $updated ?? $current->updated,
            $excluded ?? $current->excluded,
            $rejected ?? $current->rejected,
            $current->link,
            $removed ?? $current->removed,
        );

        return new self($entries, $this->anomalies);
    }
}
