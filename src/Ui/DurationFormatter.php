<?php

declare(strict_types=1);

namespace Fluxx\Ui;

/**
 * Human readable durations: milliseconds under a second, seconds with one decimal under a
 * minute, then minutes and seconds, then hours and minutes.
 */
final class DurationFormatter
{
    public static function format(int|float|null $milliseconds): string
    {
        if ($milliseconds === null) {
            return '-';
        }

        $milliseconds = max(0, (int) round($milliseconds));

        if ($milliseconds < 1000) {
            return sprintf('%d ms', $milliseconds);
        }

        $seconds = $milliseconds / 1000;

        if ($seconds < 60) {
            return sprintf('%s s', number_format($seconds, 1, '.', ''));
        }

        $wholeSeconds = (int) floor($seconds);
        $minutes = intdiv($wholeSeconds, 60);

        if ($minutes < 60) {
            return sprintf('%d min %02d s', $minutes, $wholeSeconds % 60);
        }

        return sprintf('%d h %02d min', intdiv($minutes, 60), $minutes % 60);
    }
}
