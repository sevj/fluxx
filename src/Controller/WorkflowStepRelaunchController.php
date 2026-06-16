<?php

declare(strict_types=1);

namespace Fluxx\Controller;

use Fluxx\Workflow\Lock\WorkflowExecutionLockConflict;
use Fluxx\Workflow\Relaunch\WorkflowRelaunchMode;
use Fluxx\Workflow\Relaunch\WorkflowRelaunchService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/fluxx/workflow/{workflowCode}/run/{runId}/step/{stepCode}/relaunch', name: 'fluxx_workflow_step_run_relaunch', methods: ['POST'])]
final class WorkflowStepRelaunchController extends AbstractController
{
    public function __construct(
        private readonly WorkflowRelaunchService $workflowRelaunchService,
    ) {
    }

    public function __invoke(string $workflowCode, string $runId, string $stepCode, Request $request): RedirectResponse
    {
        $token = (string) $request->request->get('_token');

        if (!$this->isCsrfTokenValid('fluxx.relaunch.step.' . $runId . '.' . $stepCode, $token)) {
            throw $this->createAccessDeniedException('Invalid relaunch token.');
        }

        $reason = $request->request->get('reason');

        try {
            $newRunId = $this->workflowRelaunchService->relaunch(
                originalRunId: $runId,
                mode: WorkflowRelaunchMode::Step,
                restartStepCode: $stepCode,
                trigger: 'ui',
                reason: is_string($reason) && $reason !== '' ? $reason : null,
                operatorUser: $this->getUser()?->getUserIdentifier(),
            );
            $this->addFlash('success', sprintf('Step relaunched. New run id: %s', $newRunId));
        } catch (WorkflowExecutionLockConflict $exception) {
            $this->addFlash('error', sprintf(
                'Workflow "%s" is locked by run "%s" for key "%s".',
                $exception->workflowCode(),
                $exception->activeRunId(),
                $exception->lockKey(),
            ));
        } catch (\Throwable $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        $redirect = $request->request->get('_redirect');

        if (is_string($redirect) && str_starts_with($redirect, '/')) {
            return $this->redirect($redirect);
        }

        return $this->redirectToRoute('fluxx_workflow_step_run_show', [
            'workflowCode' => $workflowCode,
            'runId' => $runId,
            'stepCode' => $stepCode,
        ]);
    }
}
