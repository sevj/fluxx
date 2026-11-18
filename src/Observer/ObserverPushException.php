<?php

declare(strict_types=1);

namespace Fluxx\Observer;

use RuntimeException;
use Throwable;

/**
 * Raised when the observer cannot be reached or answers with an unexpected status.
 */
final class ObserverPushException extends RuntimeException
{
    public static function unreachable(string $path, Throwable $previous): self
    {
        return new self(sprintf('The observer could not be reached on %s: %s', $path, $previous->getMessage()), 0, $previous);
    }

    public static function unexpectedStatus(string $path, int $statusCode): self
    {
        return new self(sprintf('The observer answered %d on %s.', $statusCode, $path));
    }
}
