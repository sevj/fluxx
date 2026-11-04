<?php

declare(strict_types=1);

namespace Fluxx\Controller;

use Fluxx\Http\InternalRedirectTarget;
use Fluxx\Operations\DeadConsumerPurger;
use Fluxx\Operations\OrphanRunFinisher;
use Fluxx\Operations\PendingMessageReclaimer;
use Fluxx\Operations\StaleLockReleaser;
use Fluxx\Operations\StaleWorkerPruner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

#[Route('/fluxx/runtime/self-heal', name: 'fluxx_runtime_self_heal', methods: ['POST'])]
#[IsGranted('ROLE_ADMIN')]
final class RuntimeSelfHealController extends AbstractController
{
    public function __construct(
        private readonly StaleWorkerPruner $workerPruner,
        private readonly DeadConsumerPurger $consumerPurger,
        private readonly PendingMessageReclaimer $messageReclaimer,
        private readonly StaleLockReleaser $lockReleaser,
        private readonly OrphanRunFinisher $orphanRunFinisher,
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
                'finish_orphan_runs' => $this->finishOrphanRuns(),
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

    private function pruneWorkers(): TranslatableMessage
    {
        $result = $this->workerPruner->prune(stoppedRetentionSeconds: 0, staleHeartbeatSeconds: 120);

        return new TranslatableMessage('flash.self_heal.workers_pruned', [
            '%deleted%' => $result['deletedStopped'],
            '%marked%' => $result['markedStaleAsStopped'],
        ], 'fluxx');
    }

    private function purgeDeadConsumers(): TranslatableMessage
    {
        $result = $this->consumerPurger->purge(minIdleSeconds: 60);

        return new TranslatableMessage('flash.self_heal.consumers_purged', [
            '%purged%' => count($result['purged']),
            '%skipped%' => $result['skipped'],
        ], 'fluxx');
    }

    private function reclaimPending(): TranslatableMessage
    {
        $result = $this->messageReclaimer->reclaim(minIdleSeconds: 60, count: 100);

        if ($result['claimed'] === 0) {
            return new TranslatableMessage('flash.self_heal.no_pending_message', domain: 'fluxx');
        }

        return new TranslatableMessage('flash.self_heal.messages_reclaimed', [
            '%claimed%' => $result['claimed'],
            '%consumer%' => $result['targetConsumer'],
        ], 'fluxx');
    }

    private function releaseStaleLocks(): TranslatableMessage
    {
        $released = $this->lockReleaser->releaseStale(staleHeartbeatSeconds: 120);

        if ($released === []) {
            return new TranslatableMessage('flash.self_heal.no_stale_lock', domain: 'fluxx');
        }

        return new TranslatableMessage('flash.self_heal.locks_released', ['%released%' => count($released)], 'fluxx');
    }

    private function finishOrphanRuns(): TranslatableMessage
    {
        $finished = $this->orphanRunFinisher->finishOrphanRuns();

        if ($finished === []) {
            return new TranslatableMessage('flash.self_heal.no_orphan_run', domain: 'fluxx');
        }

        return new TranslatableMessage('flash.self_heal.orphan_runs_finished', ['%finished%' => count($finished)], 'fluxx');
    }
}
