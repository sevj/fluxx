<?php

declare(strict_types=1);

namespace Fluxx\Controller;

use Fluxx\Operations\StaleLockReleaser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/fluxx/runtime/run/{runId}/lock/release', name: 'fluxx_runtime_lock_release', methods: ['POST'])]
#[IsGranted('ROLE_ADMIN')]
final class RuntimeLockReleaseController extends AbstractController
{
    public function __construct(
        private readonly StaleLockReleaser $lockReleaser,
    ) {
    }

    public function __invoke(string $runId, Request $request): RedirectResponse
    {
        $token = (string) $request->request->get('_token');

        if (!$this->isCsrfTokenValid('fluxx.runtime.lock_release', $token)) {
            throw $this->createAccessDeniedException('Invalid lock release token.');
        }

        try {
            $released = $this->lockReleaser->releaseForRun($runId);

            if ($released) {
                $this->addFlash('success', sprintf('Released the lock for run "%s".', $runId));
            } else {
                $this->addFlash('error', sprintf('No active lock was found for run "%s".', $runId));
            }
        } catch (\Throwable $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        $redirect = $request->request->get('_redirect');

        if (is_string($redirect) && str_starts_with($redirect, '/')) {
            return $this->redirect($redirect);
        }

        return $this->redirectToRoute('fluxx_runtime_index');
    }
}
