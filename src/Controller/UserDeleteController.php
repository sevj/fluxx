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
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/fluxx/users/{id}/delete', name: 'fluxx_user_delete', methods: ['POST'])]
#[IsGranted('ROLE_ADMIN')]
final class UserDeleteController extends AbstractController
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

        $currentUser = $this->getUser();

        if ($currentUser !== null && $user->email() === $currentUser->getUserIdentifier()) {
            throw new AccessDeniedException('You cannot delete your own account.');
        }

        if ($this->isCsrfTokenValid('fluxx.user.delete.' . $id, (string) $request->request->get('_csrf_token'))) {
            try {
                $this->userManager->delete($user);
                $this->addFlash('success', 'user_form.deleted');
            } catch (\Throwable $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        return $this->redirectToRoute('fluxx_user_index');
    }
}
