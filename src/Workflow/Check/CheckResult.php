<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Check;

/**
 * One control of a check step: what was expected, what was found and a few offending samples. A deviation
 * is reported, never raised: it completes the run with warnings. The subject (the object or area checked)
 * groups results in the report, and the hint says in one sentence what a deviation means.
 */
final readonly class CheckResult
{
    public const MAX_SAMPLES = 20;

    /** @var list<string> */
    public array $samples;

    /**
     * @param list<string|int|float> $samples only the first {@see self::MAX_SAMPLES} are kept, as strings
     */
    public function __construct(
        public string $name,
        public CheckOutcome $outcome,
        public ?string $expected = null,
        public ?string $actual = null,
        array $samples = [],
        public ?string $subject = null,
        public ?string $hint = null,
    ) {
        $this->samples = array_map(
            static fn (mixed $sample): string => is_scalar($sample) && !is_bool($sample)
                ? (string) $sample
                : throw new \InvalidArgumentException(sprintf('Sample of check "%s" must be a string or a number.', $name)),
            array_slice(array_values($samples), 0, self::MAX_SAMPLES),
        );
    }

    public function isDeviation(): bool
    {
        return $this->outcome === CheckOutcome::Deviation;
    }

    /**
     * @return array{name: string, outcome: string, expected: ?string, actual: ?string, samples: list<string>, subject: ?string, hint: ?string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'outcome' => $this->outcome->value,
            'expected' => $this->expected,
            'actual' => $this->actual,
            'samples' => $this->samples,
            'subject' => $this->subject,
            'hint' => $this->hint,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!is_string($data['name'] ?? null)) {
            throw new \InvalidArgumentException('Check result without name.');
        }

        $outcome = CheckOutcome::tryFrom((string) ($data['outcome'] ?? ''))
            ?? throw new \InvalidArgumentException('Unknown check outcome.');

        foreach (['expected', 'actual', 'subject', 'hint'] as $key) {
            if (($data[$key] ?? null) !== null && !is_string($data[$key])) {
                throw new \InvalidArgumentException(sprintf('Check "%s" must be a string or null.', $key));
            }
        }

        $samples = $data['samples'] ?? [];

        if (!is_array($samples)) {
            throw new \InvalidArgumentException('Check samples must be a list.');
        }

        return new self(
            $data['name'],
            $outcome,
            $data['expected'] ?? null,
            $data['actual'] ?? null,
            array_values($samples),
            $data['subject'] ?? null,
            $data['hint'] ?? null,
        );
    }
}
