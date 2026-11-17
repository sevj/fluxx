<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Report;

final readonly class ReportSection
{
    /** @var list<array{label: string, detail: ?string, status: ?string}> */
    public array $lines;

    /**
     * @param list<array{label: string, detail?: ?string, status?: ?string}> $lines
     */
    public function __construct(
        public string $title,
        array $lines = [],
        public ?string $unavailableMessage = null,
    ) {
        $this->lines = array_map(static function (mixed $line) use ($title): array {
            if (!is_array($line) || !is_string($line['label'] ?? null)) {
                throw new \InvalidArgumentException(sprintf('Each line of report section "%s" needs a label.', $title));
            }

            foreach (['detail', 'status'] as $key) {
                if (($line[$key] ?? null) !== null && !is_string($line[$key])) {
                    throw new \InvalidArgumentException(sprintf('The %s of a line of report section "%s" must be a string or null.', $key, $title));
                }
            }

            return ['label' => $line['label'], 'detail' => $line['detail'] ?? null, 'status' => $line['status'] ?? null];
        }, array_values($lines));
    }

    public static function unavailable(string $title, string $message): self
    {
        return new self($title, [], $message);
    }

    public function isAvailable(): bool
    {
        return $this->unavailableMessage === null;
    }

    /**
     * @return array{title: string, available: bool, message: ?string, lines: list<array{label: string, detail: ?string, status: ?string}>}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'available' => $this->isAvailable(),
            'message' => $this->unavailableMessage,
            'lines' => $this->lines,
        ];
    }
}
