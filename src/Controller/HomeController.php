<?php

declare(strict_types=1);

namespace Fluxx\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/fluxx', name: 'fluxx_home', methods: ['GET'])]
final class HomeController extends AbstractController
{
    public function __invoke(): RedirectResponse
    {
        return $this->redirectToRoute('fluxx_workflow_index');
    }
}
