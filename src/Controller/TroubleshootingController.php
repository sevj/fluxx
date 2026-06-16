<?php

declare(strict_types=1);

namespace Fluxx\Controller;

use Fluxx\Ui\TroubleshootingCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/fluxx/troubleshooting', name: 'fluxx_troubleshooting_index', methods: ['GET'])]
final class TroubleshootingController extends AbstractController
{
    public function __construct(
        private readonly TroubleshootingCatalog $troubleshootingCatalog,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $page = max($request->query->getInt('page', 1), 1);
        $searchQuery = trim((string) $request->query->get('q', ''));

        return $this->render('@Fluxx/troubleshooting/index.html.twig', [
            'issuePage' => $this->troubleshootingCatalog->paginate($page, searchQuery: $searchQuery),
            'searchQuery' => $searchQuery,
        ]);
    }
}
