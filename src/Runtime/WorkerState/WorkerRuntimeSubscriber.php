<?php

declare(strict_types=1);

namespace Fluxx\Runtime\WorkerState;

use Fluxx\Runtime\FluxxTransports;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;

final readonly class WorkerRuntimeSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RuntimeWorkerStateRecorder $workerStateRecorder,
        private FluxxTransports $transports,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerStartedEvent::class => 'onWorkerStarted',
            WorkerRunningEvent::class => 'onWorkerRunning',
            WorkerMessageReceivedEvent::class => 'onMessageReceived',
            WorkerMessageHandledEvent::class => 'onMessageHandled',
            WorkerMessageFailedEvent::class => 'onMessageFailed',
            WorkerStoppedEvent::class => 'onWorkerStopped',
        ];
    }

    public function onWorkerStarted(WorkerStartedEvent $event): void
    {
        if (!$this->handlesFluxxTransport($event->getWorker()->getMetadata()->getTransportNames())) {
            return;
        }

        $this->workerStateRecorder->recordStarted($this->transports->mainName());
    }

    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        if (!$this->handlesFluxxTransport($event->getWorker()->getMetadata()->getTransportNames())) {
            return;
        }

        $this->workerStateRecorder->recordHeartbeat($this->transports->mainName(), $event->isWorkerIdle());
    }

    public function onMessageReceived(WorkerMessageReceivedEvent $event): void
    {
        if (!$this->transports->isFluxxTransport($event->getReceiverName())) {
            return;
        }

        $this->workerStateRecorder->recordMessageReceived($event->getEnvelope(), $event->getReceiverName());
    }

    public function onMessageHandled(WorkerMessageHandledEvent $event): void
    {
        if (!$this->transports->isFluxxTransport($event->getReceiverName())) {
            return;
        }

        $this->workerStateRecorder->recordMessageHandled($event->getReceiverName());
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        if (!$this->transports->isFluxxTransport($event->getReceiverName())) {
            return;
        }

        $this->workerStateRecorder->recordMessageHandled($event->getReceiverName());
    }

    public function onWorkerStopped(WorkerStoppedEvent $event): void
    {
        if (!$this->handlesFluxxTransport($event->getWorker()->getMetadata()->getTransportNames())) {
            return;
        }

        $this->workerStateRecorder->recordStopped($this->transports->mainName());
    }

    /**
     * @param list<string> $transportNames
     */
    private function handlesFluxxTransport(array $transportNames): bool
    {
        return $this->transports->includesFluxxTransport($transportNames);
    }
}
