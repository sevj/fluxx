<?php

declare(strict_types=1);

namespace Fluxx\Operations;

use Fluxx\Runtime\FluxxRedisTransportConnectionFactory;
use Redis;
use RuntimeException;

/**
 * Reclaims pending (delivered-but-unacked) messages stuck in the fluxx Redis
 * consumer group and hands them back to a live worker for immediate retry.
 *
 * Symfony's Redis transport only auto-reclaims pending entries after
 * `redeliver_timeout` (default 3600s). When a consumer dies mid-processing
 * (crash, OOM, pod replacement), the message stays orphaned in its PEL until
 * that timeout. This operation uses `XAUTOCLAIM` to transfer idle pending
 * entries to a live consumer right now, bypassing the wait.
 */
final readonly class PendingMessageReclaimer
{
    private const DEFAULT_CLAIM_COUNT = 100;

    public function __construct(
        private FluxxRedisTransportConnectionFactory $connectionFactory,
    ) {
    }

    /**
     * @return array{claimed: int, targetConsumer: string, minIdleSeconds: int}
     */
    public function reclaim(int $minIdleSeconds = 60, int $count = self::DEFAULT_CLAIM_COUNT): array
    {
        if ($minIdleSeconds < 1) {
            throw new RuntimeException('The minimum idle time must be greater than zero.');
        }

        $config = $this->connectionFactory->config();
        $redis = $this->connectionFactory->create();
        $target = $this->resolveLiveConsumer($redis, $config['stream'], $config['group'], $minIdleSeconds);

        if ($target === null) {
            throw new RuntimeException('No live consumer is available to reclaim pending messages onto.');
        }

        $claimed = $this->autoClaim($redis, $config['stream'], $config['group'], $target, $minIdleSeconds, $count);

        return ['claimed' => $claimed, 'targetConsumer' => $target, 'minIdleSeconds' => $minIdleSeconds];
    }

    private function resolveLiveConsumer(Redis $redis, string $stream, string $group, int $minIdleSeconds): ?string
    {
        $consumers = \Fluxx\Runtime\RedisReplyNormalizer::mapList(
            $redis->rawCommand('XINFO', 'CONSUMERS', $stream, $group) ?: [],
        );

        if ($consumers === []) {
            return null;
        }

        $minIdleMs = $minIdleSeconds * 1000;
        $bestName = null;
        $bestIdle = null;

        foreach ($consumers as $consumer) {
            $name = (string) ($consumer['name'] ?? '');
            $idle = (int) ($consumer['idle'] ?? PHP_INT_MAX);

            if ($name === '' || $idle >= $minIdleMs) {
                continue;
            }

            if ($bestIdle === null || $idle < $bestIdle) {
                $bestName = $name;
                $bestIdle = $idle;
            }
        }

        return $bestName;
    }

    private function autoClaim(Redis $redis, string $stream, string $group, string $consumer, int $minIdleSeconds, int $count): int
    {
        $result = $redis->rawCommand(
            'XAUTOCLAIM',
            $stream,
            $group,
            $consumer,
            (string) ($minIdleSeconds * 1000),
            '0',
            (string) $count,
            'JUSTID',
        );

        if (!is_array($result) || !isset($result[1]) || !is_array($result[1])) {
            return 0;
        }

        return count($result[1]);
    }
}
