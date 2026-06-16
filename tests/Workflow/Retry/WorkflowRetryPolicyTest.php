<?php

declare(strict_types=1);

namespace Fluxx\Tests\Workflow\Retry;

use Fluxx\Workflow\Retry\WorkflowRetryBackoffStrategy;
use Fluxx\Workflow\Retry\WorkflowRetryPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkflowRetryPolicyTest extends TestCase
{
    #[Test]
    public function it_computes_fixed_backoff_delay(): void
    {
        $policy = new WorkflowRetryPolicy(
            maxRetries: 3,
            delaySeconds: 10,
            backoffStrategy: WorkflowRetryBackoffStrategy::Fixed,
        );

        self::assertSame(10_000, $policy->delayMillisecondsForAttempt(1));
        self::assertSame(10_000, $policy->delayMillisecondsForAttempt(3));
    }

    #[Test]
    public function it_computes_linear_backoff_delay(): void
    {
        $policy = new WorkflowRetryPolicy(
            maxRetries: 3,
            delaySeconds: 10,
            backoffStrategy: WorkflowRetryBackoffStrategy::Linear,
        );

        self::assertSame(10_000, $policy->delayMillisecondsForAttempt(1));
        self::assertSame(30_000, $policy->delayMillisecondsForAttempt(3));
    }

    #[Test]
    public function it_computes_exponential_backoff_delay(): void
    {
        $policy = new WorkflowRetryPolicy(
            maxRetries: 3,
            delaySeconds: 10,
            backoffStrategy: WorkflowRetryBackoffStrategy::Exponential,
        );

        self::assertSame(10_000, $policy->delayMillisecondsForAttempt(1));
        self::assertSame(40_000, $policy->delayMillisecondsForAttempt(3));
    }
}
