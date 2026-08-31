<?php

declare(strict_types=1);

namespace Fluxx\Controller;

use Fluxx\Entity\User;
use Fluxx\Repository\UserRepository;
use Fluxx\User\AssignableRoles;
use Fluxx\User\UserManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/fluxx/users/{id}/edit', name: 'fluxx_user_edit', methods: ['GET', 'POST'])]
#[IsGranted('ROLE_ADMIN')]
final class UserEditController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UserManager $userManager,
        private readonly AssignableRoles $assignableRoles,
    ) {
    }

    public function __invoke(Request $request, int $id): Response
    {
        $user = $this->userRepository->find($id);

        if ($user === null) {
            throw new NotFoundHttpException('User not found.');
        }

        $error = null;
        $selectedRoles = array_values(array_diff($user->getRoles(), ['ROLE_USER']));
        $displayName = (string) ($user->displayName() ?? '');
        $enabled = $user->enabled();
        $plainPassword = '';

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('fluxx.user.update.' . $id, (string) $request->request->get('_csrf_token'))) {
                $error = 'The security token is invalid, please retry.';
            } else {
                $displayName = trim((string) $request->request->get('display_name', ''));
                $selectedRoles = $this->assignableRoles->sanitize($request->request->all('roles'));
                $enabled = $request->request->getBoolean('enabled', false);
                $plainPassword = (string) $request->request->get('plain_password', '');

                try {
                    $this->userManager->update(
                        user: $user,
                        displayName: $displayName !== '' ? $displayName : null,
                        roles: $selectedRoles,
                        enabled: $enabled,
                        plainPassword: $plainPassword,
                    );
                    $this->addFlash('success', 'user_form.updated');

                    return $this->redirectToRoute('fluxx_user_index');
                } catch (\Throwable $exception) {
                    $error = $exception->getMessage();
                }
            }
        }

        return $this->render('@Fluxx/user/form.html.twig', [
            'mode' => 'edit',
            'user' => $user,
            'assignableRoles' => $this->assignableRoles->all(),
            'selectedRoles' => $selectedRoles,
            'email' => $user->email(),
            'displayName' => $displayName,
            'enabled' => $enabled,
            'error' => $error,
            'passwordOptional' => true,
        ]);
    }
}
