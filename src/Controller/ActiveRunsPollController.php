<?php

declare(strict_types=1);

namespace Fluxx\Controller;

use Fluxx\Ui\ActiveRunBoard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/fluxx/runs/active/poll', name: 'fluxx_run_active_poll', methods: ['GET'])]
#[IsGranted('ROLE_FLUXX_USER')]
final class ActiveRunsPollController extends AbstractController
{
    public function __construct(
        private readonly ActiveRunBoard $activeRunBoard,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        return $this->render('@Fluxx/runs/_active_runs_content.html.twig', [
            'activeBoard' => $this->activeRunBoard->page(),
        ], new Response());
    }
}
