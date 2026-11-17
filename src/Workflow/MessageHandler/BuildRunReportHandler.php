<?php

declare(strict_types=1);

namespace Fluxx\Workflow\MessageHandler;

use Fluxx\Repository\WorkflowRunLookupInterface;
use Fluxx\Repository\WorkflowRunReportRepository;
use Fluxx\Workflow\Message\BuildRunReportMessage;
use Fluxx\Workflow\Report\RunNotReportableException;
use Fluxx\Workflow\Report\RunReportBuilder;
use Fluxx\Workflow\SynchronizationRegistry;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class BuildRunReportHandler
{
    public function __construct(
        private WorkflowRunLookupInterface $workflowRunRepository,
        private SynchronizationRegistry $registry,
        private RunReportBuilder $builder,
        private WorkflowRunReportRepository $reportRepository,
    ) {
    }

    public function __invoke(BuildRunReportMessage $message): void
    {
        $run = $this->workflowRunRepository->findOneByRunId($message->runId());

        if ($run === null
            || !$this->registry->has($run->workflowName())
            || $this->registry->get($run->workflowName())->definition()->report() === null) {
            return;
        }

        try {
            $content = $this->builder->build($run);
        } catch (RunNotReportableException) {
            return;
        }

        $this->reportRepository->save($run, RunReportBuilder::FORMAT_VERSION, $content);
    }
}
