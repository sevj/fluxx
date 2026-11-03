<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Progress;

use Fluxx\Runtime\FluxxRedisTransportConnectionFactory;
use Redis;
use Throwable;

/**
 * Keeps live progress in the Redis instance of the fluxx transport, in one
 * expiring hash per run. Progress is informative: any Redis failure is
 * swallowed, and further attempts are suspended for a short while so that an
 * unreachable Redis cannot slow a step down.
 */
final class RedisStepProgressStore implements StepProgressStoreInterface
{
    private const KEY_PREFIX = 'fluxx:step-progress:';
    private const TTL_SECONDS = 3600;
    private const RETRY_DELAY_SECONDS = 30;

    private ?Redis $redis = null;
    private int $suspendedUntil = 0;

    public function __construct(
        private readonly FluxxRedisTransportConnectionFactory $connectionFactory,
    ) {
    }

    public function save(string $runId, string $stepCode, StepProgress $progress): void
    {
        $this->attempt(function (Redis $redis) use ($runId, $stepCode, $progress): void {
            $key = self::KEY_PREFIX . $runId;
            $redis->hSet($key, $stepCode, json_encode([
                'done' => $progress->done(),
                'total' => $progress->total(),
                'label' => $progress->label(),
            ], JSON_THROW_ON_ERROR));
            $redis->expire($key, self::TTL_SECONDS);
        });
    }

    public function clear(string $runId, string $stepCode): void
    {
        $this->attempt(static function (Redis $redis) use ($runId, $stepCode): void {
            $redis->hDel(self::KEY_PREFIX . $runId, $stepCode);
        });
    }

    public function forRun(string $runId): array
    {
        $entries = $this->attempt(static fn (Redis $redis): mixed => $redis->hGetAll(self::KEY_PREFIX . $runId));
        $progress = [];

        foreach (is_array($entries) ? $entries : [] as $stepCode => $encoded) {
            $data = is_string($encoded) ? json_decode($encoded, true) : null;

            if (is_array($data)) {
                $progress[(string) $stepCode] = new StepProgress(
                    (int) ($data['done'] ?? 0),
                    (int) ($data['total'] ?? 0),
                    (string) ($data['label'] ?? ''),
                );
            }
        }

        return $progress;
    }

    private function attempt(callable $operation): mixed
    {
        if (time() < $this->suspendedUntil) {
            return null;
        }

        try {
            $this->redis ??= $this->connectionFactory->create();

            return $operation($this->redis);
        } catch (Throwable) {
            $this->redis = null;
            $this->suspendedUntil = time() + self::RETRY_DELAY_SECONDS;

            return null;
        }
    }
}
