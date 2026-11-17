<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Report;

final readonly class ObjectStats
{
    /**
     * @param array<string, int> $excluded count per exclusion reason
     * @param list<array{key: string, message: string}> $rejected
     */
    public function __construct(
        public string $label,
        public int $read = 0,
        public int $created = 0,
        public int $updated = 0,
        public array $excluded = [],
        public array $rejected = [],
        public bool $link = false,
        public int $removed = 0,
    ) {
    }

    /**
     * @return array{label: string, read: int, created: int, updated: int, excluded: array<string, int>, rejected: list<array{key: string, message: string}>, link: bool, removed: int}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'read' => $this->read,
            'created' => $this->created,
            'updated' => $this->updated,
            'excluded' => $this->excluded,
            'rejected' => $this->rejected,
            'link' => $this->link,
            'removed' => $this->removed,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!is_string($data['label'] ?? null)) {
            throw new \InvalidArgumentException('Step report objects without label.');
        }

        return new self(
            label: $data['label'],
            read: (int) ($data['read'] ?? 0),
            created: (int) ($data['created'] ?? 0),
            updated: (int) ($data['updated'] ?? 0),
            excluded: $data['excluded'] ?? [],
            rejected: $data['rejected'] ?? [],
            link: ($data['link'] ?? false) === true,
            removed: (int) ($data['removed'] ?? 0),
        );
    }
}
