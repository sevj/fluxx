<?php

declare(strict_types=1);

namespace Fluxx\Tests\Entity\Enum;

use Fluxx\Entity\Enum\WorkflowRunStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkflowRunStatusTest extends TestCase
{
    #[Test]
    public function only_pending_relaunched_retrying_and_running_runs_are_active(): void
    {
        $active = array_values(array_filter(
            WorkflowRunStatus::cases(),
            static fn (WorkflowRunStatus $status): bool => $status->isActive(),
        ));

        self::assertSame(
            [WorkflowRunStatus::Pending, WorkflowRunStatus::Relaunched, WorkflowRunStatus::Retrying, WorkflowRunStatus::Running],
            $active,
        );
    }
}
