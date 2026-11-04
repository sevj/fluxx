<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Runtime;

use RuntimeException;
use Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface;
use Throwable;

/**
 * Raised once a step failure has been recorded as final: Fluxx has already applied
 * its own retry policy, so the Messenger retry strategy must not redeliver the message.
 */
final class WorkflowStepFailedException extends RuntimeException implements UnrecoverableExceptionInterface
{
    public function __construct(
        private readonly Throwable $failure,
    ) {
        parent::__construct($failure->getMessage(), 0, $failure);
    }

    public function failure(): Throwable
    {
        return $this->failure;
    }
}
