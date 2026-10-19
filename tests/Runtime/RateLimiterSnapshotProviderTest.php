<?php

declare(strict_types=1);

namespace Fluxx\Tests\Runtime;

use DateTimeImmutable;
use Fluxx\Runtime\RateLimiter\RateLimiterSnapshotProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\CompoundRateLimiterFactory;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\Reservation;

final class RateLimiterSnapshotProviderTest extends TestCase
{
    private const REFRESHED_AT = '2026-10-17 12:00:00';

    #[Test]
    public function it_reports_availability_of_every_limiter_sorted_by_name(): void
    {
        $refreshedAt = new DateTimeImmutable(self::REFRESHED_AT);
        $provider = new RateLimiterSnapshotProvider([
            'second_limiter' => new FakeRateLimiterFactory(
                new FakeLimiter(new RateLimit(37, $refreshedAt->modify('+40 seconds'), true, 100))
            ),
            'first_limiter' => new FakeRateLimiterFactory(
                new FakeLimiter(new RateLimit(35500, $refreshedAt->modify('+1 hour'), true, 36000))
            ),
        ]);

        $snapshot = $provider->snapshot($refreshedAt);

        self::assertTrue($snapshot['ok']);
        self::assertNull($snapshot['error']);
        self::assertSame(['first_limiter', 'second_limiter'], array_column($snapshot['limiters'], 'name'));

        $first = $snapshot['limiters'][0];
        self::assertSame(36000, $first['limit']);
        self::assertSame(35500, $first['remaining']);
        self::assertSame(1, $first['usedPercent']);
        self::assertTrue($first['accepted']);
        self::assertSame('2026-10-17T13:00:00+00:00', $first['retryAfterAt']);
        self::assertSame(3600000, $first['retryAfterMs']);

        $second = $snapshot['limiters'][1];
        self::assertSame(100, $second['limit']);
        self::assertSame(37, $second['remaining']);
        self::assertSame(63, $second['usedPercent']);
        self::assertSame(40000, $second['retryAfterMs']);
    }

    #[Test]
    public function it_exhausted_limiter_reports_not_accepted_and_full_usage(): void
    {
        $refreshedAt = new DateTimeImmutable(self::REFRESHED_AT);
        $provider = new RateLimiterSnapshotProvider([
            'warcraftlog_api_global' => new FakeRateLimiterFactory(
                new FakeLimiter(new RateLimit(0, $refreshedAt->modify('+11 minutes'), false, 1500))
            ),
        ]);

        $snapshot = $provider->snapshot($refreshedAt);

        $row = $snapshot['limiters'][0];
        self::assertFalse($row['accepted']);
        self::assertSame(0, $row['remaining']);
        self::assertSame(100, $row['usedPercent']);
        self::assertSame(660000, $row['retryAfterMs']);
    }

    #[Test]
    public function it_keeps_the_snapshot_ok_when_a_single_limiter_fails(): void
    {
        $refreshedAt = new DateTimeImmutable(self::REFRESHED_AT);
        $provider = new RateLimiterSnapshotProvider([
            'broken' => new ThrowingRateLimiterFactory(),
            'healthy' => new FakeRateLimiterFactory(
                new FakeLimiter(new RateLimit(5, $refreshedAt->modify('+2 seconds'), true, 10))
            ),
        ]);

        $snapshot = $provider->snapshot($refreshedAt);

        self::assertTrue($snapshot['ok']);

        $broken = $snapshot['limiters'][0];
        self::assertSame('broken', $broken['name']);
        self::assertFalse($broken['ok']);
        self::assertNotSame('', $broken['error']);
        self::assertNull($broken['remaining']);

        $healthy = $snapshot['limiters'][1];
        self::assertTrue($healthy['ok']);
        self::assertSame(5, $healthy['remaining']);
    }

    #[Test]
    public function it_omits_compound_limiters_whose_state_lives_in_their_children(): void
    {
        $refreshedAt = new DateTimeImmutable(self::REFRESHED_AT);
        $provider = new RateLimiterSnapshotProvider([
            'battlenet_api_compound' => new CompoundRateLimiterFactory([
                'perHour' => new FakeRateLimiterFactory(
                    new FakeLimiter(new RateLimit(35000, $refreshedAt->modify('+40 minutes'), true, 36000))
                ),
                'perSecond' => new FakeRateLimiterFactory(
                    new FakeLimiter(new RateLimit(0, $refreshedAt->modify('+3 seconds'), false, 100))
                ),
            ]),
            'warcraftlog_api_global' => new FakeRateLimiterFactory(
                new FakeLimiter(new RateLimit(1500, $refreshedAt->modify('+1 hour'), true, 1500))
            ),
        ]);

        $snapshot = $provider->snapshot($refreshedAt);

        self::assertSame(['warcraftlog_api_global'], array_column($snapshot['limiters'], 'name'));
    }

    #[Test]
    public function it_returns_an_empty_snapshot_without_any_registered_limiter(): void
    {
        $provider = new RateLimiterSnapshotProvider([]);

        $snapshot = $provider->snapshot(new DateTimeImmutable(self::REFRESHED_AT));

        self::assertTrue($snapshot['ok']);
        self::assertSame([], $snapshot['limiters']);
    }
}

final class FakeRateLimiterFactory implements RateLimiterFactoryInterface
{
    public function __construct(private FakeLimiter $limiter)
    {
    }

    public function create(?string $key = null): LimiterInterface
    {
        return $this->limiter;
    }
}

final class ThrowingRateLimiterFactory implements RateLimiterFactoryInterface
{
    public function create(?string $key = null): LimiterInterface
    {
        throw new \RuntimeException('storage backend is unreachable');
    }
}

final class FakeLimiter implements LimiterInterface
{
    public function __construct(private RateLimit $rateLimit)
    {
    }

    public function reserve(int $tokens = 1, ?float $maxTime = null): Reservation
    {
        throw new \LogicException('Reservation is not part of this fake.');
    }

    public function consume(int $tokens = 1): RateLimit
    {
        return $this->rateLimit;
    }

    public function reset(): void
    {
    }
}
