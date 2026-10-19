<?php

declare(strict_types=1);

namespace Fluxx\Runtime\RateLimiter;

use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\RateLimiter\CompoundRateLimiterFactory;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

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
            ];
        }
    }
}
