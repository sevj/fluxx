<?php

declare(strict_types=1);

namespace Fluxx\Observer;

use Fluxx\Ui\SystemHealth;

/**
 * Pushes this application's current system health to the Fluxx Observer.
 */
final readonly class ObserverHealthPusher
{
    public function __construct(
        private ObserverClient $client,
        private SystemHealth $systemHealth,
        private HealthSnapshotPayloadFactory $payloadFactory,
    ) {
    }

    /**
     * @throws ObserverPushException
     */
    public function push(): void
    {
        $this->client->pushHealth($this->payloadFactory->create($this->systemHealth->current()));
    }
}
