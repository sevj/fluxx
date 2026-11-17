<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Report;

/**
 * Reads the object metrics of a step that declares no {@see StepReport} from the conventional keys
 * of its metadata, so that steps written before step reports existed still appear in the run report.
 */
final class MetadataReportFallback
{
    /**
     * @param array<string, mixed> $metadata
     */
    public static function read(string $stepCode, array $metadata): ?ObjectStats
    {
        $created = self::count($metadata['created'] ?? null);
        $updated = self::count($metadata['updated'] ?? null);
        $excluded = self::excluded($metadata['excluded'] ?? null);
        $rejected = self::rejected($metadata['rejected_records'] ?? null);

        if ($created === null && $updated === null && $excluded === null && $rejected === null) {
            return null;
        }

        return new ObjectStats(
            label: $stepCode,
            created: $created ?? 0,
            updated: $updated ?? 0,
            excluded: $excluded ?? [],
            rejected: $rejected ?? [],
        );
    }

    private static function count(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }

    /**
     * @return array<string, int>|null
     */
    private static function excluded(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        $excluded = [];

        foreach ($value as $reason => $count) {
            if (is_string($reason) && self::count($count) !== null) {
                $excluded[$reason] = $count;
            }
        }

        return $excluded;
    }

    /**
     * @return list<array{key: string, message: string}>|null
     */
    private static function rejected(mixed $value): ?array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }

        $rejected = [];

        foreach ($value as $record) {
            if (!is_array($record) || !is_string($record['message'] ?? null)) {
                continue;
            }

            $key = $record['key'] ?? $record['efficy_key'] ?? null;

            if (is_string($key) || is_int($key)) {
                $rejected[] = ['key' => (string) $key, 'message' => $record['message']];
            }
        }

        return $rejected;
    }
}
