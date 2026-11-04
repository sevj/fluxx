<?php

declare(strict_types=1);

namespace Fluxx\Controller;

use Fluxx\Http\InternalRedirectTarget;
use Fluxx\Operations\StaleLockReleaser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

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
                $this->addFlash('success', new TranslatableMessage('flash.lock_released', ['%runId%' => $runId], 'fluxx'));
            } else {
                $this->addFlash('error', new TranslatableMessage('flash.lock_not_found', ['%runId%' => $runId], 'fluxx'));
            }
        } catch (\Throwable $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        $redirect = InternalRedirectTarget::extract($request);

        if ($redirect !== null) {
            return $this->redirect($redirect);
        }

        return $this->redirectToRoute('fluxx_runtime_index');
    }
}
