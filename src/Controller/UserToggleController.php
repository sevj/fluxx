<?php

declare(strict_types=1);

namespace Fluxx\Controller;

use Fluxx\Repository\UserRepository;
use Fluxx\User\UserManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/fluxx/users/{id}/toggle', name: 'fluxx_user_toggle', methods: ['POST'])]
#[IsGranted('ROLE_ADMIN')]
final class UserToggleController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UserManager $userManager,
    ) {
    }

    public function __invoke(Request $request, int $id): Response
    {
        $user = $this->userRepository->find($id);

        if ($user === null) {
            throw new NotFoundHttpException('User not found.');
        }

        $target = $id . ':' . ($user->enabled() ? 'disable' : 'enable');

        if ($this->isCsrfTokenValid('fluxx.user.toggle.' . $target, (string) $request->request->get('_csrf_token'))) {
            $this->userManager->update(
                user: $user,
                displayName: $user->displayName(),
                roles: $user->getRoles(),
                enabled: !$user->enabled(),
            );
            $this->addFlash('success', $user->enabled() ? 'user_form.flash_enabled' : 'user_form.flash_disabled');
        }

        return $this->redirectToRoute('fluxx_user_index');
    }
}
