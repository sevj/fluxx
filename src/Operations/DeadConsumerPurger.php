<?php

declare(strict_types=1);

namespace Fluxx\Operations;

use Doctrine\DBAL\Connection;
use Fluxx\Runtime\FluxxRedisTransportConnectionFactory;
use Redis;

/**
 * Removes dead Redis consumer-group entries from the fluxx transport.
 *
 * Redis keeps a consumer entry for every consumer that ever joined the group
 * and never removes it automatically. When workers restart (notably under
 * Kubernetes, where the pod hostname changes on every rollout), dead consumers
 * accumulate indefinitely and poll>er the runtime view.
 *
 * A consumer is considered dead when its Redis idle time exceeds the threshold
 * AND no live {@see \Fluxx\Entity\RuntimeWorkerState} (non-stopped, fresh
 * heartbeat) references the same worker name. Purging also clears the
 * consumer's pending entries from the group PEL.
 */
final readonly class DeadConsumerPurger
{
    private const DEFAULT_TRANSPORT = 'fluxx';

    public function __construct(
        private FluxxRedisTransportConnectionFactory $connectionFactory,
        private Connection $connection,
    ) {
    }

    /**
     * @return array{purged: list<array{name: string, pending: int}>, skipped: int}
     */
    public function purge(int $minIdleSeconds = 60, ?string $transportName = null): array
    {
        $transport = $transportName ?? self::DEFAULT_TRANSPORT;
        $config = $this->connectionFactory->config();
        $redis = $this->connectionFactory->create();

        $consumers = $this->listConsumers($redis, $config['stream'], $config['group']);
        $liveWorkerNames = $this->liveWorkerNames($transport, $minIdleSeconds);

        $purged = [];
        $skipped = 0;
        $minIdleMs = $minIdleSeconds * 1000;

        foreach ($consumers as $consumer) {
            $name = $consumer['name'];
            $idle = $consumer['idle'];

            if ($idle < $minIdleMs) {
                continue;
            }

            if (in_array($name, $liveWorkerNames, true)) {
                ++$skipped;
                continue;
            }

            $redis->rawCommand('XGROUP', 'DELCONSUMER', $config['stream'], $config['group'], $name);
            $purged[] = ['name' => $name, 'pending' => $consumer['pending']];
        }

        return ['purged' => $purged, 'skipped' => $skipped];
    }

    /**
     * @return list<array{name: string, idle: int, pending: int}>
     */
    private function listConsumers(Redis $redis, string $stream, string $group): array
    {
        $raw = $redis->rawCommand('XINFO', 'CONSUMERS', $stream, $group) ?: [];

        return array_map(
            static function (array $consumer): array {
                return [
                    'name' => (string) ($consumer['name'] ?? ''),
                    'idle' => (int) ($consumer['idle'] ?? 0),
                    'pending' => (int) ($consumer['pending'] ?? 0),
                ];
            },
            \Fluxx\Runtime\RedisReplyNormalizer::mapList($raw),
        );
    }

    /**
     * @return list<string>
     */
    private function liveWorkerNames(string $transport, int $heartbeatSeconds): array
    {
        $threshold = (new \DateTimeImmutable(sprintf('-%d seconds', $heartbeatSeconds)))->format('Y-m-d H:i:s');

        $rows = $this->connection->fetchAllAssociative(
            'SELECT worker_name FROM fluxx_runtime_worker_state
             WHERE transport_name = :transport
               AND status <> :stopped
               AND last_heartbeat_at >= :threshold',
            [
                'transport' => $transport,
                'stopped' => 'stopped',
                'threshold' => $threshold,
            ],
        );

        return array_map(static fn (array $row): string => (string) $row['worker_name'], $rows);
    }
}
