<?php

declare(strict_types=1);

namespace Fluxx\Ui;

/**
 * The stored report has another format version or a content the view cannot read: it has to be rebuilt.
 */
final class UnreadableRunReportException extends \RuntimeException
{
}
