<?php

declare(strict_types=1);

namespace Fluxx\Runtime\WorkerState;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Fluxx\Entity\RuntimeWorkerState;
use Fluxx\Repository\RuntimeWorkerStateRepository;
use Fluxx\Repository\WorkflowRunRepository;
use Fluxx\Workflow\Message\RunWorkflowStepMessage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

final readonly class RuntimeWorkerStateRecorder
{
    public function __construct(
        #[Autowire('%env(MESSENGER_TRANSPORT_FLUXX_DSN)%')]
        private string $fluxxTransportDsn,
        private EntityManagerInterface $entityManager,
        private RuntimeWorkerStateRepository $workerStateRepository,
        private WorkflowRunRepository $workflowRunRepository,
    ) {
    }

    public function recordStarted(string $receiverName): void
    {
        $workerState = $this->getOrCreateWorkerState($receiverName);
        $workerState->markIdle(new DateTimeImmutable(), $this->resolveMemoryBytes());

        $this->entityManager->flush();
    }

    public function recordHeartbeat(string $receiverName, bool $workerIsIdle): void
    {
        $workerState = $this->getOrCreateWorkerState($receiverName);

        if ($workerIsIdle) {
            $workerState->markIdle(new DateTimeImmutable(), $this->resolveMemoryBytes());
        } else {
            $workerState->touch(new DateTimeImmutable(), $this->resolveMemoryBytes());
        }

        $this->entityManager->flush();
    }

    public function recordMessageReceived(Envelope $envelope, string $receiverName): void
    {
        $workerState = $this->getOrCreateWorkerState($receiverName);
        $message = $envelope->getMessage();
        $workflowCode = null;
        $runId = null;
        $stepCode = null;

        if ($message instanceof RunWorkflowStepMessage) {
            $runId = $message->runId();
            $stepCode = $message->stepCode();
            $workflowCode = $this->workflowRunRepository->findOneByRunId($runId)?->workflowName();
        }

        $workerState->markProcessing(
            messageClass: $message::class,
            transportMessageId: $envelope->last(TransportMessageIdStamp::class)?->getId(),
            workflowCode: $workflowCode,
            runId: $runId,
            stepCode: $stepCode,
            startedAt: new DateTimeImmutable(),
            memoryBytes: $this->resolveMemoryBytes(),
        );

        $this->entityManager->flush();
    }

    public function recordMessageHandled(string $receiverName): void
    {
        $workerState = $this->getOrCreateWorkerState($receiverName);
        $workerState->markIdle(new DateTimeImmutable(), $this->resolveMemoryBytes());

        $this->entityManager->flush();
    }

    public function recordStopped(string $receiverName): void
    {
        $workerState = $this->getOrCreateWorkerState($receiverName);
        $workerState->markStopped(new DateTimeImmutable(), $this->resolveMemoryBytes());

        $this->entityManager->flush();
    }

    private function getOrCreateWorkerState(string $receiverName): RuntimeWorkerState
    {
        $workerName = $this->resolveWorkerName();
        $host = $this->resolveHost();
        $pid = $this->resolvePid();
        $workerState = $this->workerStateRepository->findOneByWorkerName($workerName);

        if ($workerState === null) {
            $workerState = new RuntimeWorkerState(
                workerName: $workerName,
                transportName: $this->resolveTransportName($receiverName),
                host: $host,
                pid: $pid,
                receiverName: $receiverName,
            );

            $this->entityManager->persist($workerState);
        } else {
            $workerState->syncIdentity(
                transportName: $this->resolveTransportName($receiverName),
                host: $host,
                pid: $pid,
                receiverName: $receiverName,
            );
        }

        return $workerState;
    }

    private function resolveWorkerName(): string
    {
        $parts = parse_url($this->fluxxTransportDsn);
        $pathParts = array_values(array_filter(explode('/', trim((string) ($parts['path'] ?? ''), '/'))));

        return $pathParts[2] ?? sprintf('%s-%s', $this->resolveHost(), $this->resolvePid());
    }

    private function resolveTransportName(string $receiverName): string
    {
        return $receiverName !== '' ? $receiverName : 'fluxx';
    }

    private function resolveHost(): string
    {
        return gethostname() ?: php_uname('n');
    }

    private function resolvePid(): int
    {
        return getmypid() ?: 0;
    }

    private function resolveMemoryBytes(): int
    {
        return max(memory_get_usage(true), memory_get_peak_usage(true));
    }
}
