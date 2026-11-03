<?php

declare(strict_types=1);

namespace Fluxx\Tests\Ui;

use Fluxx\Ui\DurationFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DurationFormatterTest extends TestCase
{
    /**
     * @return iterable<string, array{int|float|null, string}>
     */
    public static function durations(): iterable
    {
        yield 'unknown' => [null, '-'];
        yield 'zero' => [0, '0 ms'];
        yield 'milliseconds' => [842, '842 ms'];
        yield 'one second' => [1000, '1.0 s'];
        yield 'seconds with decimal' => [41253, '41.3 s'];
        yield 'float input' => [1234.6, '1.2 s'];
        yield 'minutes' => [6 * 60_000 + 2_400, '6 min 02 s'];
        yield 'hours' => [64 * 60_000 + 59_000, '1 h 04 min'];
    }

    #[DataProvider('durations')]
    public function testFormat(int|float|null $milliseconds, string $expected): void
    {
        self::assertSame($expected, DurationFormatter::format($milliseconds));
    }
}
