<?php

declare(strict_types=1);

namespace Fluxx\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/fluxx', name: 'fluxx_home', methods: ['GET'])]
#[IsGranted('ROLE_FLUXX_USER')]
final class HomeController extends AbstractController
{
    public function __invoke(): RedirectResponse
    {
        return $this->redirectToRoute('fluxx_workflow_index');
    }
}
