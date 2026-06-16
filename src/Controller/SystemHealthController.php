<?php

declare(strict_types=1);

namespace Fluxx\Controller;

use Fluxx\Ui\SystemHealth;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/fluxx/health', name: 'fluxx_system_health', methods: ['GET'])]
final class SystemHealthController extends AbstractController
{
    public function __construct(
        private readonly SystemHealth $systemHealth,
    ) {
    }

    public function __invoke(): Response
    {
        return $this->render('@Fluxx/runtime/health.html.twig', [
            'health' => $this->systemHealth->current(),
        ]);
    }
}
