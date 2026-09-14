<?php

declare(strict_types=1);

namespace Fluxx\Controller;

use Fluxx\Ui\RunDetails;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/fluxx/workflow/{workflowCode}/run/{runId}', name: 'fluxx_workflow_run_show', methods: ['GET'])]
#[IsGranted('ROLE_FLUXX_USER')]
final class RunDetailController extends AbstractController
{
    public function __construct(
        private readonly RunDetails $runDetails,
    ) {
    }

    public function __invoke(string $workflowCode, string $runId): Response
    {
        try {
            $run = $this->runDetails->forWorkflowCodeAndRunId($workflowCode, $runId);
        } catch (InvalidArgumentException $exception) {
            throw $this->createNotFoundException(
                sprintf('Run "%s/%s" was not found.', $workflowCode, $runId),
                $exception,
            );
        }

        return $this->render('@Fluxx/workflow/run_show.html.twig', [
            'run' => $run,
        ]);
    }
}
