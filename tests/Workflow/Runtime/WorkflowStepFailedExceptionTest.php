<?php

declare(strict_types=1);

namespace Fluxx\Tests\Workflow\Runtime;

use Fluxx\Workflow\Message\RunWorkflowStepMessage;
use Fluxx\Workflow\Runtime\WorkflowStepFailedException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Throwable;

final class WorkflowStepFailedExceptionTest extends TestCase
{
    #[Test]
    public function it_keeps_the_original_failure(): void
    {
        $failure = new RuntimeException('boom');
        $exception = new WorkflowStepFailedException($failure);

        self::assertSame($failure, $exception->failure());
        self::assertSame($failure, $exception->getPrevious());
        self::assertSame('boom', $exception->getMessage());
    }

    #[Test]
    public function messenger_does_not_redeliver_a_final_step_failure(): void
    {
        self::assertFalse($this->messengerWillRetry(new WorkflowStepFailedException(new RuntimeException('boom'))));
    }

    #[Test]
    public function messenger_still_redelivers_any_other_failure(): void
    {
        self::assertTrue($this->messengerWillRetry(new RuntimeException('database unavailable')));
    }

    private function messengerWillRetry(Throwable $failure): bool
    {
        $sender = $this->createStub(SenderInterface::class);
        $sender->method('send')->willReturnArgument(0);

        $listener = new SendFailedMessageForRetryListener(
            new ServiceLocator(['fluxx' => static fn (): SenderInterface => $sender]),
            new ServiceLocator(['fluxx' => static fn (): MultiplierRetryStrategy => new MultiplierRetryStrategy(3)]),
        );

        $envelope = new Envelope(new RunWorkflowStepMessage('run-1', 'fetch'));
        $event = new WorkerMessageFailedEvent($envelope, 'fluxx', new HandlerFailedException($envelope, [$failure]));
        $listener->onMessageFailed($event);

        return $event->willRetry();
    }
}
