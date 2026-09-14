<?php

declare(strict_types=1);

namespace Fluxx\Controller;

use Fluxx\Operations\WorkflowRunFilterFactory;
use Fluxx\Ui\RunCatalog;
use Fluxx\Ui\WorkflowRunFilters;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/fluxx/runs', name: 'fluxx_run_index', methods: ['GET'])]
#[IsGranted('ROLE_FLUXX_USER')]
final class RunCatalogController extends AbstractController
{
    public function __construct(
        private readonly RunCatalog $runCatalog,
        private readonly WorkflowRunFilterFactory $workflowRunFilterFactory,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $page = max($request->query->getInt('page', 1), 1);
        $filters = $this->workflowRunFilterFactory->fromArray($request->query->all());

        return $this->render('@Fluxx/runs/index.html.twig', [
            'runPage' => $this->runCatalog->paginate($page, 20, $filters),
            'executionFilters' => $filters,
            'workflowChoices' => $this->runCatalog->availableWorkflows(),
        ]);
    }
}
