<?php

declare(strict_types=1);

namespace Fluxx\Workflow\MessageHandler;

use Fluxx\Runtime\FluxxTransports;
use Fluxx\Workflow\Message\RunWorkflowStepMessage;
use Fluxx\Workflow\Runtime\FluxxRuntime;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class RunWorkflowStepHandler
{
    public function __construct(
        private FluxxRuntime $fluxxRuntime,
        private MessageBusInterface $messageBus,
        private FluxxTransports $transports = new FluxxTransports(),
    ) {
    }

    public function __invoke(RunWorkflowStepMessage $message): void
    {
        // The run has started: its next steps go before the runs still waiting to start.
        foreach ($this->fluxxRuntime->runStep($message->runId(), $message->stepCode()) as $nextStep) {
            StepMessageDispatcher::dispatch(
                $this->messageBus,
                $message->runId(),
                $nextStep['code'],
                stamps: $this->transports->continuationStamps(),
            );
        }
    }
}
