<?php

declare(strict_types=1);

namespace Fluxx\Tests\Workflow\Lock;

use Fluxx\Entity\Enum\WorkflowExecutionLockScope;
use Fluxx\Workflow\Lock\WorkflowExecutionLockConfiguration;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkflowExecutionLockConfigurationTest extends TestCase
{
    #[Test]
    public function it_exposes_its_configuration_values(): void
    {
        $configuration = new WorkflowExecutionLockConfiguration(
            scope: WorkflowExecutionLockScope::WorkflowSourceTarget,
            staleTimeoutSeconds: 300,
        );

        self::assertSame(WorkflowExecutionLockScope::WorkflowSourceTarget, $configuration->scope());
        self::assertSame(300, $configuration->staleTimeoutSeconds());
        self::assertNull($configuration->businessPartitionMetadataKey());
    }

    #[Test]
    public function it_requires_a_metadata_key_for_business_partition_scope(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WorkflowExecutionLockConfiguration(
            scope: WorkflowExecutionLockScope::WorkflowBusinessPartition,
        );
    }

    #[Test]
    public function it_rejects_a_non_positive_stale_timeout(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WorkflowExecutionLockConfiguration(
            scope: WorkflowExecutionLockScope::Workflow,
            staleTimeoutSeconds: 0,
        );
    }
}
