<?php

declare(strict_types=1);

namespace Fluxx\Controller;

use Fluxx\Http\InternalRedirectTarget;
use Fluxx\Repository\WorkflowRunLookupInterface;
use Fluxx\Repository\WorkflowRunReportRepository;
use Fluxx\Workflow\Report\RunNotReportableException;
use Fluxx\Workflow\Report\RunReportBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

#[Route('/fluxx/workflow/{workflowCode}/run/{runId}/report/rebuild', name: 'fluxx_workflow_run_report_rebuild', methods: ['POST'])]
#[IsGranted('ROLE_ADMIN')]
final class WorkflowRunReportRebuildController extends AbstractController
{
    public function __construct(
        private readonly WorkflowRunLookupInterface $workflowRunRepository,
        private readonly RunReportBuilder $builder,
        private readonly WorkflowRunReportRepository $reportRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(string $workflowCode, string $runId, Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('fluxx.report.rebuild.' . $runId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid report rebuild token.');
        }

        $run = $this->workflowRunRepository->findOneByRunId($runId);

        if ($run === null || $run->workflowName() !== $workflowCode) {
            $this->addFlash('error', new TranslatableMessage('run_report.flash.run_not_found', ['%runId%' => $runId], 'fluxx'));
        } elseif (!RunReportBuilder::isReportable($run)) {
            $this->addFlash('error', new TranslatableMessage('run_report.flash.not_reportable', ['%runId%' => $runId], 'fluxx'));
        } else {
            try {
                $this->reportRepository->save($run, RunReportBuilder::FORMAT_VERSION, $this->builder->build($run));
                $this->addFlash('success', new TranslatableMessage('run_report.flash.rebuilt', ['%runId%' => $runId], 'fluxx'));
            } catch (RunNotReportableException) {
                $this->addFlash('error', new TranslatableMessage('run_report.flash.not_reportable', ['%runId%' => $runId], 'fluxx'));
            } catch (\Throwable $exception) {
                $this->logger->error('The report of run "{run_id}" could not be rebuilt: {message}', [
                    'run_id' => $runId,
                    'message' => $exception->getMessage(),
                    'exception' => $exception,
                ]);
                $this->addFlash('error', new TranslatableMessage('run_report.flash.rebuild_failed', ['%runId%' => $runId], 'fluxx'));
            }
        }

        return $this->redirect(
            InternalRedirectTarget::extract($request)
                ?? $this->generateUrl('fluxx_workflow_run_show', ['workflowCode' => $workflowCode, 'runId' => $runId, 'tab' => 'report']),
        );
    }
}
