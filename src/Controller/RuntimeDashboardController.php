<?php

declare(strict_types=1);

namespace Fluxx\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/fluxx/runtime', name: 'fluxx_runtime_index', methods: ['GET'])]
final class RuntimeDashboardController extends AbstractController
{
    public function __invoke(): Response
    {
        return $this->render('@Fluxx/runtime/index.html.twig');
    }
}
