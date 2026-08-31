<?php

declare(strict_types=1);

namespace Fluxx\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/fluxx/runtime', name: 'fluxx_runtime_index', methods: ['GET'])]
#[IsGranted('ROLE_ADMIN')]
final class RuntimeDashboardController extends AbstractController
{
    public function __invoke(): Response
    {
        return $this->render('@Fluxx/runtime/index.html.twig');
    }
}
