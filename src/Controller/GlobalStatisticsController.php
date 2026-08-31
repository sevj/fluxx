<?php

declare(strict_types=1);

namespace Fluxx\Controller;

use Fluxx\Ui\GlobalStatistics;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/fluxx/statistics', name: 'fluxx_statistics_index', methods: ['GET'])]
final class GlobalStatisticsController extends AbstractController
{
    public function __construct(
        private readonly GlobalStatistics $globalStatistics,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $range = trim((string) $request->query->get('range', ''));

        return $this->render('@Fluxx/statistics/index.html.twig', [
            'globalStatistics' => $this->globalStatistics->build($range),
        ]);
    }
}
