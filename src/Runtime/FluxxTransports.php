<?php

declare(strict_types=1);

namespace Fluxx\Runtime;

use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * The Messenger transports Fluxx works on: the main one, where new runs start, and the optional
 * continuation one, where the next steps of started runs wait. Workers consume the continuation
 * transport first, so a started run finishes before new ones begin.
 */
final readonly class FluxxTransports
{
    public function __construct(
        #[Autowire('%fluxx.runtime.transport_name%')]
        private string $mainName = 'fluxx',
        #[Autowire('%env(MESSENGER_TRANSPORT_FLUXX_DSN)%')]
        private ?string $mainDsn = null,
        #[Autowire('%fluxx.runtime.continuation_transport_name%')]
        private ?string $continuationName = null,
        #[Autowire('%env(default::MESSENGER_TRANSPORT_FLUXX_CONTINUE_DSN)%')]
        private ?string $continuationDsn = null,
    ) {
    }

    public function mainName(): string
    {
        return $this->mainName;
    }

    public function continuationName(): ?string
    {
        return $this->continuationName;
    }

    /**
     * In the order workers must consume them: continuation first.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return $this->continuationName === null ? [$this->mainName] : [$this->continuationName, $this->mainName];
    }

    public function isFluxxTransport(string $name): bool
    {
        return in_array($name, $this->names(), true);
    }

    /**
     * @param list<string> $names
     */
    public function includesFluxxTransport(array $names): bool
    {
        return array_intersect($names, $this->names()) !== [];
    }

    /**
     * Stamps for a step of a run that has already started. Empty without a continuation transport,
     * so the message follows the host's routing as before.
     *
     * @return list<StampInterface>
     */
    public function continuationStamps(): array
    {
        return $this->continuationName === null ? [] : [new TransportNamesStamp([$this->continuationName])];
    }

    /**
     * One Redis connection per transport, keyed by transport name, in consumption order.
     *
     * @return array<string, FluxxRedisTransportConnectionFactory>
     */
    public function connections(): array
    {
        $connections = [];

        if ($this->continuationName !== null) {
            if ($this->continuationDsn === null || $this->continuationDsn === '') {
                throw new RuntimeException(sprintf(
                    'MESSENGER_TRANSPORT_FLUXX_CONTINUE_DSN must be set when fluxx.runtime.continuation_transport_name is "%s".',
                    $this->continuationName,
                ));
            }

            $connections[$this->continuationName] = new FluxxRedisTransportConnectionFactory($this->continuationDsn);
        }

        $connections[$this->mainName] = new FluxxRedisTransportConnectionFactory((string) $this->mainDsn);

        return $connections;
    }

    /**
     * @return array<string, string> DSN by transport name, in consumption order
     */
    public function dsns(): array
    {
        $dsns = [];

        foreach (array_keys($this->connections()) as $name) {
            $dsns[$name] = $name === $this->mainName ? (string) $this->mainDsn : (string) $this->continuationDsn;
        }

        return $dsns;
    }
}
