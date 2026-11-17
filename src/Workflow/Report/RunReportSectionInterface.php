<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Report;

use Fluxx\Entity\WorkflowRun;

/**
 * An application section of the run report. Implementations are services, found by their class name:
 * list them in {@see WorkflowReportConfiguration}. A section that throws is shown as unavailable.
 */
interface RunReportSectionInterface
{
    public function build(WorkflowRun $run): ReportSection;
}
