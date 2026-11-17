<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Report;

/**
 * @internal
 */
final class Utf8
{
    /**
     * Reports carry host data, sometimes legacy latin-1 text: one invalid byte would make the JSON
     * column refuse the whole step update.
     *
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    public static function scrub(array $data): array
    {
        $scrubbed = [];

        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $key = mb_scrub($key, 'UTF-8');
            }

            $scrubbed[$key] = match (true) {
                is_string($value) => mb_scrub($value, 'UTF-8'),
                is_array($value) => self::scrub($value),
                default => $value,
            };
        }

        return $scrubbed;
    }
}
