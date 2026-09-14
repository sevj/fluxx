<?php

declare(strict_types=1);

namespace Fluxx\Controller;

use Fluxx\Http\InternalRedirectTarget;
use Fluxx\Operations\DeadConsumerPurger;
use Fluxx\Operations\PendingMessageReclaimer;
use Fluxx\Operations\StaleLockReleaser;
use Fluxx\Operations\StaleWorkerPruner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/fluxx/runtime/self-heal', name: 'fluxx_runtime_self_heal', methods: ['POST'])]
#[IsGranted('ROLE_ADMIN')]
final class RuntimeSelfHealController extends AbstractController
{
    public function __construct(
        private readonly StaleWorkerPruner $workerPruner,
        private readonly DeadConsumerPurger $consumerPurger,
        private readonly PendingMessageReclaimer $messageReclaimer,
        private readonly StaleLockReleaser $lockReleaser,
    ) {
    }

    public function __invoke(Request $request): RedirectResponse
    {
        $token = (string) $request->request->get('_token');
        $action = (string) $request->request->get('action');

        if (!$this->isCsrfTokenValid('fluxx.runtime.self_heal.' . $action, $token)) {
            throw $this->createAccessDeniedException('Invalid self-healing token.');
        }

        try {
            $message = match ($action) {
                'prune_workers' => $this->pruneWorkers(),
                'purge_dead_consumers' => $this->purgeDeadConsumers(),
                'reclaim_pending' => $this->reclaimPending(),
                'release_stale_locks' => $this->releaseStaleLocks(),
                default => throw new \InvalidArgumentException(sprintf('Unknown self-healing action "%s".', $action)),
            };

            $this->addFlash('success', $message);
        } catch (\Throwable $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        $redirect = InternalRedirectTarget::extract($request);

        if ($redirect !== null) {
            return $this->redirect($redirect);
        }

        return $this->redirectToRoute('fluxx_runtime_index');
    }

    private function pruneWorkers(): string
    {
        $result = $this->workerPruner->prune(stoppedRetentionSeconds: 0, staleHeartbeatSeconds: 120);

        return sprintf(
            'Pruned %d stopped worker(s) deleted, %d stale ghost(s) marked as stopped.',
            $result['deletedStopped'],
            $result['markedStaleAsStopped'],
        );
    }

    private function purgeDeadConsumers(): string
    {
        $result = $this->consumerPurger->purge(minIdleSeconds: 60);

        return sprintf(
            'Purged %d dead consumer(s); skipped %d live consumer(s).',
            count($result['purged']),
            $result['skipped'],
        );
    }

    private function reclaimPending(): string
    {
        $result = $this->messageReclaimer->reclaim(minIdleSeconds: 60, count: 100);

        if ($result['claimed'] === 0) {
            return 'No pending message was eligible for reclaim.';
        }

        return sprintf(
            'Reclaimed %d pending message(s) onto consumer "%s".',
            $result['claimed'],
            $result['targetConsumer'],
        );
    }

    private function releaseStaleLocks(): string
    {
        $released = $this->lockReleaser->releaseStale(staleHeartbeatSeconds: 120);

        if ($released === []) {
            return 'No stale lock was found.';
        }

        return sprintf('Released %d stale lock(s).', count($released));
    }
}
