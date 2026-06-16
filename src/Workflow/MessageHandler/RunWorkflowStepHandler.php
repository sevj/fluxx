<?php

declare(strict_types=1);

namespace Fluxx\Workflow\MessageHandler;

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
    ) {
    }

    public function __invoke(RunWorkflowStepMessage $message): void
    {
        foreach ($this->fluxxRuntime->runStep($message->runId(), $message->stepCode()) as $nextStep) {
            StepMessageDispatcher::dispatch($this->messageBus, $message->runId(), $nextStep['code']);
        }
    }
}
