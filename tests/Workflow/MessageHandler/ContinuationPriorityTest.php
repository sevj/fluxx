<?php

declare(strict_types=1);

namespace Fluxx\Tests\Workflow\MessageHandler;

use Fluxx\Runtime\FluxxTransports;
use Fluxx\Workflow\Message\RunWorkflowStepMessage;
use Fluxx\Workflow\MessageHandler\StepMessageDispatcher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Worker;

final class ContinuationPriorityTest extends TestCase
{
    private const STEPS = ['fetch', 'transform', 'write'];

    #[Test]
    public function a_step_dispatched_for_a_started_run_targets_the_continuation_transport(): void
    {
        $bus = new RecordingBus();
        $transports = new FluxxTransports('fluxx', null, 'fluxx_continue');

        StepMessageDispatcher::dispatch($bus, 'run-1', 'write', 1500, $transports->continuationStamps());

        $envelope = $bus->envelopes[0];
        self::assertSame(['fluxx_continue'], $envelope->last(TransportNamesStamp::class)?->getTransportNames());
        self::assertSame(1500, $envelope->last(DelayStamp::class)?->getDelay());
    }

    #[Test]
    public function without_stamps_or_delay_the_bare_message_is_dispatched_as_before(): void
    {
        $bus = new RecordingBus();

        StepMessageDispatcher::dispatch($bus, 'run-1', 'fetch');

        self::assertSame([], $bus->envelopes[0]->all());
    }

    #[Test]
    public function with_a_continuation_transport_each_started_run_finishes_before_the_next_one_starts(): void
    {
        self::assertSame(
            ['run-1:fetch', 'run-1:transform', 'run-1:write', 'run-2:fetch', 'run-2:transform', 'run-2:write', 'run-3:fetch', 'run-3:transform', 'run-3:write'],
            $this->processThreeRuns(new FluxxTransports('fluxx', null, 'fluxx_continue')),
        );
    }

    #[Test]
    public function without_a_continuation_transport_every_run_starts_before_any_finishes(): void
    {
        self::assertSame(
            ['run-1:fetch', 'run-2:fetch', 'run-3:fetch', 'run-1:transform', 'run-2:transform', 'run-3:transform', 'run-1:write', 'run-2:write', 'run-3:write'],
            $this->processThreeRuns(new FluxxTransports('fluxx')),
        );
    }

    /**
     * Three runs of a three-step workflow queued at once, then one worker reading the Fluxx
     * transports in their order, as `messenger:consume fluxx_continue fluxx` does.
     *
     * @return list<string> the steps in the order they ran
     */
    private function processThreeRuns(FluxxTransports $transports): array
    {
        $queues = [];
        foreach ($transports->names() as $name) {
            $queues[$name] = new InMemoryTransport();
        }

        $executed = [];
        $bus = null;
        $handler = static function (RunWorkflowStepMessage $message) use (&$executed, &$bus, $transports): void {
            $executed[] = $message->runId().':'.$message->stepCode();
            $next = self::STEPS[array_search($message->stepCode(), self::STEPS, true) + 1] ?? null;

            if ($next !== null) {
                StepMessageDispatcher::dispatch($bus, $message->runId(), $next, stamps: $transports->continuationStamps());
            }
        };

        $senders = new class($queues) implements ContainerInterface {
            /** @param array<string, InMemoryTransport> $queues */
            public function __construct(private array $queues)
            {
            }

            public function get(string $id): InMemoryTransport
            {
                return $this->queues[$id];
            }

            public function has(string $id): bool
            {
                return isset($this->queues[$id]);
            }
        };

        $bus = new MessageBus([
            // The host routes RunWorkflowStepMessage to the main transport; continuations override it.
            new SendMessageMiddleware(new SendersLocator([RunWorkflowStepMessage::class => [$transports->mainName()]], $senders)),
            new HandleMessageMiddleware(new HandlersLocator([RunWorkflowStepMessage::class => [$handler]])),
        ]);

        foreach (['run-1', 'run-2', 'run-3'] as $runId) {
            StepMessageDispatcher::dispatch($bus, $runId, self::STEPS[0]);
        }

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(WorkerRunningEvent::class, static function (WorkerRunningEvent $event): void {
            if ($event->isWorkerIdle()) {
                $event->getWorker()->stop();
            }
        });

        (new Worker($queues, $bus, $dispatcher))->run(['sleep' => 0]);

        return $executed;
    }
}

final class RecordingBus implements MessageBusInterface
{
    /** @var list<Envelope> */
    public array $envelopes = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $envelope = Envelope::wrap($message, $stamps);
        $this->envelopes[] = $envelope;

        return $envelope;
    }
}
