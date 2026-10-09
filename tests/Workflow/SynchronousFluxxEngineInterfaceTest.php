<?php

declare(strict_types=1);

namespace Fluxx\Tests\Workflow;

use Fluxx\Entity\WorkflowRun;
use Fluxx\Workflow\SynchronousFluxxEngine;
use Fluxx\Workflow\SynchronousFluxxEngineInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SynchronousFluxxEngineInterfaceTest extends TestCase
{
    #[Test]
    public function synchronous_engine_implements_interface(): void
    {
        self::assertContains(
            SynchronousFluxxEngineInterface::class,
            class_implements(SynchronousFluxxEngine::class) ?: [],
        );
    }
}
