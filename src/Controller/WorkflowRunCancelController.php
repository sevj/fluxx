<?php

declare(strict_types=1);

namespace Fluxx\Controller;

use Fluxx\Workflow\Cancellation\WorkflowCancellationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/fluxx/workflow/{workflowCode}/run/{runId}/cancel', name: 'fluxx_workflow_run_cancel', methods: ['POST'])]
final class WorkflowRunCancelController extends AbstractController
{
    public function __construct(
        private readonly WorkflowCancellationService $workflowCancellationService,
    ) {
    }

    public function __invoke(string $workflowCode, string $runId, Request $request): RedirectResponse
    {
        $token = (string) $request->request->get('_token');

        if (!$this->isCsrfTokenValid('fluxx.cancel.run.' . $runId, $token)) {
            throw $this->createAccessDeniedException('Invalid cancellation token.');
        }

        $reason = $request->request->get('reason');

        try {
            $cancelled = $this->workflowCancellationService->cancel(
                runId: $runId,
                trigger: 'ui',
                reason: is_string($reason) && $reason !== '' ? $reason : null,
                operatorUser: $this->getUser()?->getUserIdentifier(),
            );

            if ($cancelled) {
                $this->addFlash('success', sprintf('Run %s cancelled.', $runId));
            } else {
                $this->addFlash('error', sprintf('Run %s cannot be cancelled in its current state.', $runId));
            }
        } catch (\Throwable $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        $redirect = $request->request->get('_redirect');

        if (is_string($redirect) && str_starts_with($redirect, '/')) {
            return $this->redirect($redirect);
        }

        return $this->redirectToRoute('fluxx_workflow_show', ['code' => $workflowCode]);
    }
}
