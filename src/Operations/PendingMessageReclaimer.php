<?php

declare(strict_types=1);

namespace Fluxx\Operations;

use Fluxx\Runtime\FluxxTransports;
use Redis;
use RuntimeException;

/**
 * Reclaims pending (delivered-but-unacked) messages stuck in the Fluxx Redis
 * consumer groups and hands them back to a live worker for immediate retry.
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
        private FluxxTransports $transports,
    ) {
    }

    /**
     * Runs on every Fluxx transport, each onto its own live consumer. A transport without a live
     * consumer is skipped; it fails only when none has one.
     *
     * @return array{
     *     claimed: int,
     *     targetConsumer: string,
     *     minIdleSeconds: int,
     *     transports: array<string, array{claimed: int, targetConsumer: ?string}>
     * }
     */
    public function reclaim(int $minIdleSeconds = 60, int $count = self::DEFAULT_CLAIM_COUNT): array
    {
        if ($minIdleSeconds < 1) {
            throw new RuntimeException('The minimum idle time must be greater than zero.');
        }

        $claimed = 0;
        $targets = [];
        $transports = [];

        foreach ($this->transports->connections() as $transport => $connectionFactory) {
            $config = $connectionFactory->config();
            $redis = $connectionFactory->create();
            $target = $this->resolveLiveConsumer($redis, $config['stream'], $config['group'], $minIdleSeconds);
            $transportClaimed = $target === null
                ? 0
                : $this->autoClaim($redis, $config['stream'], $config['group'], $target, $minIdleSeconds, $count);

            $transports[$transport] = ['claimed' => $transportClaimed, 'targetConsumer' => $target];
            $claimed += $transportClaimed;

            if ($target !== null) {
                $targets[$target] = true;
            }
        }

        if ($targets === []) {
            throw new RuntimeException('No live consumer is available to reclaim pending messages onto.');
        }

        return [
            'claimed' => $claimed,
            'targetConsumer' => implode(', ', array_keys($targets)),
            'minIdleSeconds' => $minIdleSeconds,
            'transports' => $transports,
        ];
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
