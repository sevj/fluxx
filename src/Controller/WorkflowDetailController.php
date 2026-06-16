<?php

declare(strict_types=1);

namespace Fluxx\Controller;

use Fluxx\Ui\WorkflowDetails;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/fluxx/workflow/{code}', name: 'fluxx_workflow_show', methods: ['GET'])]
final class WorkflowDetailController extends AbstractController
{
    public function __construct(
        private readonly WorkflowDetails $workflowDetails,
    ) {
    }

    public function __invoke(string $code): Response
    {
        try {
            $workflow = $this->workflowDetails->overviewForCode($code);
        } catch (InvalidArgumentException $exception) {
            throw $this->createNotFoundException(sprintf('Workflow "%s" was not found.', $code), $exception);
        }

        return $this->render('@Fluxx/workflow/show.html.twig', [
            'workflow' => $workflow,
        ]);
    }
}
