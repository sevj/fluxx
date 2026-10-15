<?php

declare(strict_types=1);

namespace Fluxx\Controller;

use Fluxx\Ui\ActiveRunBoard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/fluxx/runs/active', name: 'fluxx_run_active', methods: ['GET'])]
#[IsGranted('ROLE_FLUXX_USER')]
final class ActiveRunsController extends AbstractController
{
    public function __construct(
        private readonly ActiveRunBoard $activeRunBoard,
    ) {
    }

    public function __invoke(): Response
    {
        return $this->render('@Fluxx/runs/active.html.twig', [
            'activeBoard' => $this->activeRunBoard->page(),
        ]);
    }
}
