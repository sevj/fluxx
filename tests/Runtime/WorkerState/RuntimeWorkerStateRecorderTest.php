<?php

declare(strict_types=1);

namespace Fluxx\Tests\Runtime\WorkerState;

use Fluxx\Runtime\FluxxTransports;
use Fluxx\Runtime\WorkerState\RuntimeWorkerStateRecorder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class RuntimeWorkerStateRecorderTest extends TestCase
{
    #[Test]
    public function a_worker_reading_any_fluxx_queue_is_recorded_under_the_main_transport(): void
    {
        $resolve = $this->transportNameResolver(new FluxxTransports('fluxx', null, 'fluxx_continue'));

        self::assertSame('fluxx', $resolve('fluxx_continue'));
        self::assertSame('fluxx', $resolve('fluxx'));
        self::assertSame('fluxx', $resolve(''));
        self::assertSame('async', $resolve('async'));
    }

    /**
     * @return \Closure(string): string
     */
    private function transportNameResolver(FluxxTransports $transports): \Closure
    {
        $recorder = (new ReflectionClass(RuntimeWorkerStateRecorder::class))->newInstanceWithoutConstructor();
        (new ReflectionClass(RuntimeWorkerStateRecorder::class))->getProperty('transports')->setValue($recorder, $transports);

        return \Closure::bind(
            fn (string $receiverName): string => $this->resolveTransportName($receiverName),
            $recorder,
            RuntimeWorkerStateRecorder::class,
        );
    }
}
