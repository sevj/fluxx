<?php

declare(strict_types=1);

namespace Fluxx\Runtime\WorkerState;

use DateTimeImmutable;
use Fluxx\Operations\OrphanRunFinisher;
use Fluxx\Runtime\FluxxTransports;
use Fluxx\Settings\RuntimeSettingsManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;

/**
 * Automatically finalizes orphaned runs (see {@see OrphanRunFinisher}).
 *
 * Listens to worker idle ticks on the Fluxx transports and runs the finisher at
 * most once per interval, so a worker death (crash, OOM, container loss) is
 * healed by the next worker cycle without any operator action. Exceptions are
 * swallowed: healing is best-effort and must never break message consumption.
 */
final class OrphanRunWatchdog implements EventSubscriberInterface
{
    private const CHECK_INTERVAL_SECONDS = 60;

    private ?DateTimeImmutable $lastCheckAt = null;

    public function __construct(
        private OrphanRunFinisher $orphanRunFinisher,
        private RuntimeSettingsManager $runtimeSettingsManager,
        private FluxxTransports $transports,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerRunningEvent::class => 'onWorkerRunning',
        ];
    }

    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        if (
            !$this->transports->includesFluxxTransport($event->getWorker()->getMetadata()->getTransportNames())
            || !$event->isWorkerIdle()
        ) {
            return;
        }

        $now = new DateTimeImmutable();

        if ($this->lastCheckAt !== null && $now->getTimestamp() - $this->lastCheckAt->getTimestamp() < self::CHECK_INTERVAL_SECONDS) {
            return;
        }

        $this->lastCheckAt = $now;

        try {
            $this->orphanRunFinisher->finishOrphanRuns($this->runtimeSettingsManager->get()->staleLockTimeoutSeconds);
        } catch (\Throwable) {
            $this->lastCheckAt = null;
        }
    }
}
