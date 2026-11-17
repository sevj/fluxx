<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Report;

/**
 * An exception that keeps the report of the step it stops: the runtime stores it on the failed step run.
 */
interface ReportCarryingExceptionInterface extends \Throwable
{
    /**
     * @return array<string, mixed> the stored format of {@see StepReport::toArray()}
     */
    public function report(): array;
}
