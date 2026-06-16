<?php

declare(strict_types=1);

namespace Fluxx\Tests\Ui;

use Fluxx\Ui\WorkflowDetails;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class WorkflowGraphLayoutTest extends TestCase
{
    #[Test]
    public function it_keeps_distinct_terminal_lanes_for_parallel_branches(): void
    {
        $reflection = new ReflectionClass(WorkflowDetails::class);
        $details = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('computeTerminalLanePaths');
        $method->setAccessible(true);

        $lanePaths = $method->invoke($details, [
            'companies_transform' => [0],
            'contacts_transform' => [1],
            'companies_write' => [0],
            'contacts_write' => [1],
            'contacts_linker' => [],
        ]);

        self::assertSame([[0], [1]], $lanePaths);
    }
}
