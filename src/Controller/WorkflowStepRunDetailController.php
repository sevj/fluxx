<?php

declare(strict_types=1);

namespace Fluxx\Controller;

use Fluxx\Ui\StepRunDetails;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/fluxx/workflow/{workflowCode}/run/{runId}/step/{stepCode}', name: 'fluxx_workflow_step_run_show', methods: ['GET'])]
final class WorkflowStepRunDetailController extends AbstractController
{
    public function __construct(
        private readonly StepRunDetails $stepRunDetails,
    ) {
    }

    public function __invoke(string $workflowCode, string $runId, string $stepCode): Response
    {
        try {
            $stepRun = $this->stepRunDetails->forWorkflowRunAndStepCode($workflowCode, $runId, $stepCode);
        } catch (InvalidArgumentException $exception) {
            throw $this->createNotFoundException(
                sprintf('Step run "%s/%s/%s" was not found.', $workflowCode, $runId, $stepCode),
                $exception,
            );
        }

        return $this->render('@Fluxx/workflow/step_run_show.html.twig', [
            'stepRun' => $stepRun,
        ]);
    }
}
