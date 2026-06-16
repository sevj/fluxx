<?php

declare(strict_types=1);

namespace Fluxx\Workflow\MessageHandler;

use Fluxx\Workflow\Message\RunWorkflowStepMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

final class StepMessageDispatcher
{
    public static function dispatch(
        MessageBusInterface $messageBus,
        string $runId,
        string $stepCode,
        int $delayMilliseconds = 0,
    ): void {
        $message = new RunWorkflowStepMessage($runId, $stepCode);

        if ($delayMilliseconds > 0) {
            $messageBus->dispatch(new Envelope($message, [new DelayStamp($delayMilliseconds)]));

            return;
        }

        $messageBus->dispatch($message);
    }
}
