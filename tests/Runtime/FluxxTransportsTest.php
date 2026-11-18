<?php

declare(strict_types=1);

namespace Fluxx\Tests\Runtime;

use Fluxx\Runtime\FluxxTransports;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

final class FluxxTransportsTest extends TestCase
{
    #[Test]
    public function without_a_continuation_transport_it_keeps_a_single_transport_and_adds_no_stamp(): void
    {
        $transports = new FluxxTransports('fluxx', 'redis://localhost:6379/fluxx/fluxx/worker');

        self::assertSame(['fluxx'], $transports->names());
        self::assertSame([], $transports->continuationStamps());
        self::assertSame(['fluxx'], array_keys($transports->connections()));
        self::assertNull($transports->continuationName());
    }

    #[Test]
    public function the_continuation_transport_comes_first_and_receives_the_steps_of_started_runs(): void
    {
        $transports = new FluxxTransports(
            'fluxx',
            'redis://localhost:6379/fluxx/fluxx/worker',
            'fluxx_continue',
            'redis://localhost:6379/fluxx_continue/fluxx/worker',
        );

        self::assertSame(['fluxx_continue', 'fluxx'], $transports->names());
        self::assertSame(['fluxx_continue', 'fluxx'], array_keys($transports->connections()));
        self::assertSame('fluxx_continue', $transports->connections()['fluxx_continue']->config()['stream']);
        self::assertSame('fluxx', $transports->connections()['fluxx']->config()['stream']);

        $stamps = $transports->continuationStamps();
        self::assertCount(1, $stamps);
        self::assertInstanceOf(TransportNamesStamp::class, $stamps[0]);
        self::assertSame(['fluxx_continue'], $stamps[0]->getTransportNames());
    }

    #[Test]
    public function it_recognizes_its_transports(): void
    {
        $transports = new FluxxTransports('fluxx', 'redis://localhost/fluxx/fluxx', 'fluxx_continue', 'redis://localhost/fluxx_continue/fluxx');

        self::assertTrue($transports->isFluxxTransport('fluxx'));
        self::assertTrue($transports->isFluxxTransport('fluxx_continue'));
        self::assertFalse($transports->isFluxxTransport('failed'));
        self::assertTrue($transports->includesFluxxTransport(['async', 'fluxx_continue']));
        self::assertFalse($transports->includesFluxxTransport(['async', 'failed']));
    }

    #[Test]
    public function a_continuation_transport_without_its_dsn_is_reported_when_the_streams_are_needed(): void
    {
        $transports = new FluxxTransports('fluxx', 'redis://localhost/fluxx/fluxx', 'fluxx_continue', null);

        // Dispatching only needs the name.
        self::assertCount(1, $transports->continuationStamps());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MESSENGER_TRANSPORT_FLUXX_CONTINUE_DSN');
        $transports->connections();
    }
}
