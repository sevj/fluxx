<?php

declare(strict_types=1);

namespace Fluxx\Tests\Entity;

use Fluxx\Entity\Enum\WorkflowExecutionLockScope;
use Fluxx\Entity\WorkflowExecutionLock;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkflowExecutionLockTest extends TestCase
{
    #[Test]
    public function it_releases_only_once_and_preserves_the_first_reason(): void
    {
        $lock = new WorkflowExecutionLock(
            workflowName: 'contacts',
            ownerRunId: 'run-1',
            lockKey: 'contacts:CSV:Hubspot',
            scope: WorkflowExecutionLockScope::WorkflowSourceTarget,
        );

        $lock->release('completed');
        $firstReleasedAt = $lock->releasedAt();

        $lock->release('failed');

        self::assertNotNull($firstReleasedAt);
        self::assertSame($firstReleasedAt, $lock->releasedAt());
        self::assertSame('completed', $lock->releaseReason());
    }
}
