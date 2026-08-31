<?php

declare(strict_types=1);

namespace Fluxx\Runtime;

use Redis;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Builds a raw {@see Redis} client targeting the fluxx Messenger transport,
 * centralising the DSN parsing that was previously duplicated across the
 * snapshot provider and the reset command.
 */
final readonly class FluxxRedisTransportConnectionFactory
{
    /**
     * @param array{host: string, port: int, password: ?string, database: ?int, stream: string, group: string}|null $config
     */
    public function __construct(
        #[Autowire('%env(MESSENGER_TRANSPORT_FLUXX_DSN)%')]
        private string $fluxxTransportDsn,
        private ?array $config = null,
    ) {
    }

    /**
     * @return array{host: string, port: int, password: ?string, database: ?int, stream: string, group: string}
     */
    public function config(): array
    {
        if ($this->config !== null) {
            return $this->config;
        }

        return $this->parseDsn($this->fluxxTransportDsn);
    }

    public function create(): Redis
    {
        $config = $this->config();

        if (!class_exists(Redis::class)) {
            throw new RuntimeException('The Redis extension is required to operate on the fluxx transport.');
        }

        $redis = new Redis();

        if (!$redis->connect($config['host'], $config['port'], 2.0)) {
            throw new RuntimeException('Could not connect to Redis for fluxx transport operations.');
        }

        if ($config['password'] !== null && $config['password'] !== '') {
            $redis->auth($config['password']);
        }

        if ($config['database'] !== null) {
            $redis->select($config['database']);
        }

        return $redis;
    }

    /**
     * @return array{host: string, port: int, password: ?string, database: ?int, stream: string, group: string}
     */
    private function parseDsn(string $dsn): array
    {
        $parts = parse_url($dsn);

        if ($parts === false || !isset($parts['host'])) {
            throw new RuntimeException('The fluxx transport DSN is invalid.');
        }

        $pathParts = array_values(array_filter(explode('/', trim((string) ($parts['path'] ?? ''), '/'))));

        if (($pathParts[0] ?? null) === null || ($pathParts[1] ?? null) === null) {
            throw new RuntimeException('The fluxx transport DSN must define a stream and a consumer group.');
        }

        parse_str($parts['query'] ?? '', $query);

        return [
            'host' => (string) $parts['host'],
            'port' => isset($parts['port']) ? (int) $parts['port'] : 6379,
            'password' => isset($parts['pass']) ? (string) $parts['pass'] : null,
            'database' => isset($query['dbindex']) ? (int) $query['dbindex'] : null,
            'stream' => $pathParts[0],
            'group' => $pathParts[1],
        ];
    }
}
