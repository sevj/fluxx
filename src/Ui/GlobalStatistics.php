<?php

declare(strict_types=1);

namespace Fluxx\Ui;

use Fluxx\Workflow\SynchronizationRegistry;

final readonly class GlobalStatistics
{
    public function __construct(
        private WorkflowStatisticsViewFactory $statisticsViewFactory,
        private SynchronizationRegistry $registry,
    ) {
    }

    /**
     * An unknown workflow code is ignored, so the filter never displays an inconsistent selection.
     */
    public function build(string $statisticsRange = WorkflowStatisticsViewFactory::DEFAULT_RANGE, string $workflowCode = ''): WorkflowStatisticsView
    {
        return $this->statisticsViewFactory->build(
            $statisticsRange,
            $this->registry->has($workflowCode) ? $this->registry->get($workflowCode)->definition() : null,
        );
    }
}
