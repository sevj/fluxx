<?php

declare(strict_types=1);

namespace Fluxx\Workflow\MessageHandler;

use Fluxx\Workflow\Message\RunWorkflowStepMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;

final class StepMessageDispatcher
{
    /**
     * @param list<StampInterface> $stamps added to the envelope, such as the continuation transport of
     *                                     {@see \Fluxx\Runtime\FluxxTransports::continuationStamps()}
     */
    public static function dispatch(
        MessageBusInterface $messageBus,
        string $runId,
        string $stepCode,
        int $delayMilliseconds = 0,
        array $stamps = [],
    ): void {
        if ($delayMilliseconds > 0) {
            $stamps[] = new DelayStamp($delayMilliseconds);
        }

        $message = new RunWorkflowStepMessage($runId, $stepCode);
        $messageBus->dispatch($stamps === [] ? $message : new Envelope($message, $stamps));
    }
}
