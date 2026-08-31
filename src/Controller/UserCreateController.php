<?php

declare(strict_types=1);

namespace Fluxx\Controller;

use Fluxx\User\AssignableRoles;
use Fluxx\User\UserManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/fluxx/users/new', name: 'fluxx_user_create', methods: ['GET', 'POST'])]
#[IsGranted('ROLE_ADMIN')]
final class UserCreateController extends AbstractController
{
    public function __construct(
        private readonly UserManager $userManager,
        private readonly AssignableRoles $assignableRoles,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $email = '';
        $displayName = '';
        $selectedRoles = [];
        $enabled = true;
        $error = null;

        if ($request->isMethod('POST')) {
            $email = trim((string) $request->request->get('email', ''));
            $displayName = trim((string) $request->request->get('display_name', ''));
            $plainPassword = (string) $request->request->get('plain_password', '');
            $selectedRoles = $this->assignableRoles->sanitize($request->request->all('roles'));
            $enabled = $request->request->getBoolean('enabled', false);

            if (!$this->isCsrfTokenValid('fluxx.user.create', (string) $request->request->get('_csrf_token'))) {
                $error = 'The security token is invalid, please retry.';
            } else {
                try {
                    $this->userManager->create(
                        email: $email,
                        plainPassword: $plainPassword,
                        roles: $selectedRoles,
                        displayName: $displayName !== '' ? $displayName : null,
                        enabled: $enabled,
                    );
                    $this->addFlash('success', 'user_form.created');

                    return $this->redirectToRoute('fluxx_user_index');
                } catch (\Throwable $exception) {
                    $error = $exception->getMessage();
                }
            }
        }

        return $this->render('@Fluxx/user/form.html.twig', [
            'mode' => 'create',
            'user' => null,
            'assignableRoles' => $this->assignableRoles->all(),
            'selectedRoles' => $selectedRoles,
            'email' => $email,
            'displayName' => $displayName,
            'enabled' => $enabled,
            'error' => $error,
            'passwordOptional' => false,
        ]);
    }
}
