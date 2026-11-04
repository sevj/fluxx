<?php

declare(strict_types=1);

namespace Fluxx\Controller;

use Fluxx\Http\InternalRedirectTarget;
use Fluxx\Workflow\Cancellation\WorkflowCancellationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

#[Route('/fluxx/workflow/{workflowCode}/run/{runId}/cancel', name: 'fluxx_workflow_run_cancel', methods: ['POST'])]
#[IsGranted('ROLE_ADMIN')]
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
                $this->addFlash('success', new TranslatableMessage('flash.run_cancelled', ['%runId%' => $runId], 'fluxx'));
            } else {
                $this->addFlash('error', new TranslatableMessage('flash.run_not_cancellable', ['%runId%' => $runId], 'fluxx'));
            }
        } catch (\Throwable $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        $redirect = InternalRedirectTarget::extract($request);

        if ($redirect !== null) {
            return $this->redirect($redirect);
        }

        return $this->redirectToRoute('fluxx_workflow_show', ['code' => $workflowCode]);
    }
}
