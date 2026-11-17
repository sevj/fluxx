<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Report;

/**
 * Declares that a report is built for every run of the workflow that completes.
 */
final readonly class WorkflowReportConfiguration
{
    /**
     * @param list<class-string<RunReportSectionInterface>> $sections application services adding their own section
     */
    public function __construct(
        private array $sections = [],
    ) {
        foreach ($sections as $section) {
            if (!is_string($section) || !is_subclass_of($section, RunReportSectionInterface::class)) {
                throw new \InvalidArgumentException(sprintf(
                    'A report section must be the class of a service implementing %s.',
                    RunReportSectionInterface::class,
                ));
            }
        }
    }

    /**
     * @return list<class-string<RunReportSectionInterface>>
     */
    public function sections(): array
    {
        return array_values($this->sections);
    }
}
