<?php

declare(strict_types=1);

namespace Fluxx\Runtime;

/**
 * Normalises raw Redis command replies (lists of flat field/value arrays)
 * into lists of associative maps.
 *
 * Mirrors the parsing used by {@see FluxxRuntimeSnapshotProvider} for
 * `XINFO CONSUMERS` replies, centralised so self-healing operations share
 * the exact same shape.
 */
final readonly class RedisReplyNormalizer
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function mapList(mixed $reply): array
    {
        if (!is_array($reply)) {
            return [];
        }

        $normalized = [];

        foreach ($reply as $item) {
            if (!is_array($item)) {
                continue;
            }

            $normalized[] = self::map($item);
        }

        return $normalized;
    }

    /**
     * @param array<int|string, mixed> $item
     * @return array<string, mixed>
     */
    public static function map(array $item): array
    {
        if (array_is_list($item)) {
            $normalized = [];
            $count = count($item);

            for ($index = 0; $index + 1 < $count; $index += 2) {
                if (!is_string($item[$index])) {
                    continue;
                }

                $normalized[$item[$index]] = $item[$index + 1];
            }

            return $normalized;
        }

        return $item;
    }
}
