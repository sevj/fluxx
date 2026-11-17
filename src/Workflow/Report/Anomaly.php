<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Report;

final readonly class Anomaly
{
    /**
     * @param array<string, int|string> $lines
     */
    public function __construct(
        public string $title,
        public array $lines,
        public Severity $severity = Severity::Warning,
    ) {
    }

    /**
     * @return array{title: string, severity: string, lines: array<string, int|string>}
     */
    public function toArray(): array
    {
        return ['title' => $this->title, 'severity' => $this->severity->value, 'lines' => $this->lines];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!is_string($data['title'] ?? null)) {
            throw new \InvalidArgumentException('Step report anomaly without title.');
        }

        $severity = Severity::tryFrom((string) ($data['severity'] ?? Severity::Warning->value))
            ?? throw new \InvalidArgumentException('Unknown step report anomaly severity.');

        return new self(
            title: $data['title'],
            lines: $data['lines'] ?? [],
            severity: $severity,
        );
    }
}
