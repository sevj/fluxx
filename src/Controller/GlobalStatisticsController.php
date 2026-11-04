<?php

declare(strict_types=1);

namespace Fluxx\Controller;

use Fluxx\Ui\GlobalStatistics;
use Fluxx\Ui\RunCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/fluxx/statistics', name: 'fluxx_statistics_index', methods: ['GET'])]
#[IsGranted('ROLE_FLUXX_USER')]
final class GlobalStatisticsController extends AbstractController
{
    public function __construct(
        private readonly GlobalStatistics $globalStatistics,
        private readonly RunCatalog $runCatalog,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $range = trim((string) $request->query->get('range', ''));
        $workflow = trim((string) $request->query->get('workflow', ''));

        return $this->render('@Fluxx/statistics/index.html.twig', [
            'globalStatistics' => $this->globalStatistics->build($range, $workflow),
            'workflowChoices' => $this->runCatalog->availableWorkflows(),
        ]);
    }
}
