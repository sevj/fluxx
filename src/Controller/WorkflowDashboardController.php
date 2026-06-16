<?php

declare(strict_types=1);

namespace Fluxx\Controller;

use Fluxx\Ui\WorkflowCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/fluxx/workflow', name: 'fluxx_workflow_index', methods: ['GET'])]
final class WorkflowDashboardController extends AbstractController
{
    public function __construct(
        private readonly WorkflowCatalog $workflowCatalog,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $page = max($request->query->getInt('page', 1), 1);
        $searchQuery = trim((string) $request->query->get('q', ''));

        return $this->render('@Fluxx/workflow/index.html.twig', [
            'workflowPage' => $this->workflowCatalog->paginate($page, searchQuery: $searchQuery),
            'searchQuery' => $searchQuery,
        ]);
    }
}
