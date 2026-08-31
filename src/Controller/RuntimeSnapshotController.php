<?php

declare(strict_types=1);

namespace Fluxx\Controller;

use Fluxx\Runtime\FluxxRuntimeSnapshotProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/fluxx/runtime/snapshot', name: 'fluxx_runtime_snapshot', methods: ['GET'])]
#[IsGranted('ROLE_ADMIN')]
final class RuntimeSnapshotController extends AbstractController
{
    public function __construct(
        private readonly FluxxRuntimeSnapshotProvider $snapshotProvider,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $response = $this->json($this->snapshotProvider->snapshot());
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }
}
