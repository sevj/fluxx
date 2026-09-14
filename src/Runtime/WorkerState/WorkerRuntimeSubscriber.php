<?php

declare(strict_types=1);

namespace Fluxx\Runtime\WorkerState;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
        #[Autowire('%fluxx.runtime.transport_name%')]
        private string $transportName,
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

        $this->workerStateRecorder->recordStarted($this->transportName);
    }

    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        if (!$this->handlesFluxxTransport($event->getWorker()->getMetadata()->getTransportNames())) {
            return;
        }

        $this->workerStateRecorder->recordHeartbeat($this->transportName, $event->isWorkerIdle());
    }

    public function onMessageReceived(WorkerMessageReceivedEvent $event): void
    {
        if ($event->getReceiverName() !== $this->transportName) {
            return;
        }

        $this->workerStateRecorder->recordMessageReceived($event->getEnvelope(), $event->getReceiverName());
    }

    public function onMessageHandled(WorkerMessageHandledEvent $event): void
    {
        if ($event->getReceiverName() !== $this->transportName) {
            return;
        }

        $this->workerStateRecorder->recordMessageHandled($event->getReceiverName());
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        if ($event->getReceiverName() !== $this->transportName) {
            return;
        }

        $this->workerStateRecorder->recordMessageHandled($event->getReceiverName());
    }

    public function onWorkerStopped(WorkerStoppedEvent $event): void
    {
        if (!$this->handlesFluxxTransport($event->getWorker()->getMetadata()->getTransportNames())) {
            return;
        }

        $this->workerStateRecorder->recordStopped($this->transportName);
    }

    /**
     * @param list<string> $transportNames
     */
    private function handlesFluxxTransport(array $transportNames): bool
    {
        return in_array($this->transportName, $transportNames, true);
    }
}
