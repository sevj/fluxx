<?php

declare(strict_types=1);

namespace Fluxx\Runtime\RateLimiter;

use DateTimeImmutable;
use Symfony\Component\RateLimiter\Policy\Window;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\RateLimiter\CompoundRateLimiterFactory;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

final readonly class RateLimiterSnapshotProvider
{
    public function __construct(
        #[AutowireIterator(tag: 'rate_limiter', indexAttribute: 'name')]
        private iterable $limiters,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(DateTimeImmutable $refreshedAt): array
    {
        try {
            $rows = [];

            foreach ($this->limiters as $name => $limiterFactory) {
                if ($limiterFactory instanceof CompoundRateLimiterFactory) {
                    continue;
                }

                $rows[] = $this->buildRow((string) $name, $limiterFactory, $refreshedAt);
            }

            usort($rows, static fn (array $left, array $right): int => strcasecmp($left['name'], $right['name']));

            return ['ok' => true, 'error' => null, 'limiters' => $rows];
        } catch (\Throwable $exception) {
            return ['ok' => false, 'error' => $exception->getMessage(), 'limiters' => []];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRow(string $name, RateLimiterFactoryInterface $limiterFactory, DateTimeImmutable $refreshedAt): array
    {
        try {
            $rateLimit = $limiterFactory->create()->consume(0);
            $retryAfter = $rateLimit->getRetryAfter();
            $limit = $rateLimit->getLimit();
            $remaining = max($rateLimit->getRemainingTokens(), 0);
            $usedPercent = $limit > 0 ? (int) round((($limit - $remaining) / $limit) * 100) : null;
            $resetInSeconds = $this->resetInSeconds($name, $limiterFactory, $limit, $refreshedAt);

            return [
                'name' => $name,
                'ok' => true,
                'error' => null,
                'limit' => $limit,
                'remaining' => $remaining,
                'usedPercent' => $usedPercent,
                'accepted' => $rateLimit->isAccepted(),
                'retryAfterAt' => $retryAfter->format(DATE_ATOM),
                'retryAfterMs' => max(0, ((int) $retryAfter->format('Uv')) - ((int) $refreshedAt->format('Uv'))),
                'resetAt' => $resetInSeconds !== null ? $refreshedAt->modify(sprintf('+%d seconds', $resetInSeconds))->format(DATE_ATOM) : null,
                'resetMs' => $resetInSeconds !== null ? $resetInSeconds * 1000 : null,
            ];
        } catch (\Throwable $exception) {
            return [
                'name' => $name,
                'ok' => false,
                'error' => $exception->getMessage(),
                'limit' => null,
                'remaining' => null,
                'usedPercent' => null,
                'accepted' => null,
                'retryAfterAt' => null,
                'retryAfterMs' => null,
                'resetAt' => null,
                'resetMs' => null,
            ];
        }
    }

    private function resetInSeconds(string $name, RateLimiterFactoryInterface $limiterFactory, int $limit, DateTimeImmutable $refreshedAt): ?int
    {
        $storage = $this->storageOf($limiterFactory);

        if ($storage === null || $limit < 1) {
            return null;
        }

        $window = $storage->fetch($name . '-');

        if (!$window instanceof Window || $window->getHitCount() < 1) {
            return null;
        }

        $seconds = $window->calculateTimeForTokens($limit, (float) $refreshedAt->format('U.u'));

        return $seconds > 0 ? $seconds : null;
    }

    private function storageOf(RateLimiterFactoryInterface $limiterFactory): ?StorageInterface
    {
        if (!$limiterFactory instanceof RateLimiterFactory) {
            return null;
        }

        try {
            $storage = (new \ReflectionProperty(RateLimiterFactory::class, 'storage'))->getValue($limiterFactory);
        } catch (\ReflectionException) {
            return null;
        }

        return $storage instanceof StorageInterface ? $storage : null;
    }
}
