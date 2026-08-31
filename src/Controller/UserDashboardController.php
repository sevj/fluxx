<?php

declare(strict_types=1);

namespace Fluxx\Controller;

use Fluxx\Ui\UserCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/fluxx/users', name: 'fluxx_user_index', methods: ['GET'])]
#[IsGranted('ROLE_ADMIN')]
final class UserDashboardController extends AbstractController
{
    public function __construct(
        private readonly UserCatalog $userCatalog,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $page = max($request->query->getInt('page', 1), 1);
        $searchQuery = trim((string) $request->query->get('q', ''));

        return $this->render('@Fluxx/user/index.html.twig', [
            'userPage' => $this->userCatalog->paginate($page, searchQuery: $searchQuery),
            'searchQuery' => $searchQuery,
        ]);
    }
}
