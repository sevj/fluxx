<?php

declare(strict_types=1);

namespace Fluxx\Runtime;

use DateTimeImmutable;
use Fluxx\Entity\RuntimeWorkerState;
use Fluxx\Repository\RuntimeWorkerStateRepository;
use Fluxx\Repository\WorkflowExecutionLockRepository;
use Fluxx\Repository\WorkflowRunRepository;
use Fluxx\Repository\WorkflowStepRunRepository;
use Fluxx\StepType\StepTypeRegistry;
use Fluxx\Workflow\Message\RunWorkflowStepMessage;
use Fluxx\Workflow\SynchronizationRegistry;
use Redis;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Bridge\Redis\Transport\Connection;
use Symfony\Component\Messenger\Bridge\Redis\Transport\RedisReceiver;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final readonly class FluxxRuntimeSnapshotProvider
{
    private const MESSAGE_LIMIT = 100;
    private const ACTIVE_CONSUMER_IDLE_THRESHOLD_MS = 5000;
    private const ORPHAN_WORKER_HEARTBEAT_TTL_MS = 30000;

    public function __construct(
        #[Autowire('%env(MESSENGER_TRANSPORT_FLUXX_DSN)%')]
        private string $fluxxTransportDsn,
        #[Autowire(service: '.signing.messenger.default_serializer')]
        private SerializerInterface $transportSerializer,
        private RuntimeWorkerStateRepository $runtimeWorkerStateRepository,
        private WorkflowExecutionLockRepository $workflowExecutionLockRepository,
        private WorkflowRunRepository $workflowRunRepository,
        private WorkflowStepRunRepository $workflowStepRunRepository,
        private SynchronizationRegistry $registry,
        private StepTypeRegistry $stepTypeRegistry,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $refreshedAt = new DateTimeImmutable();

        try {
            return $this->buildSnapshot($refreshedAt);
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'refreshedAt' => $refreshedAt->format(DATE_ATOM),
                'error' => $exception->getMessage(),
                'summary' => [
                'backlogCount' => 0,
                'inFlightCount' => 0,
                'consumerCount' => 0,
                'activeLockCount' => 0,
                'visibleMessageCount' => 0,
                'oldestMessageAgeMs' => null,
                'oldestPendingAgeMs' => null,
                ],
                'queue' => [
                    'name' => 'fluxx',
                    'stream' => null,
                    'group' => null,
                ],
                'workers' => [],
                'activeLocks' => [],
                'messages' => [],
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSnapshot(DateTimeImmutable $refreshedAt): array
    {
        $receiver = $this->createFluxxReceiver();
        $redisSnapshot = $this->fetchRedisSnapshot($refreshedAt);
        $workerRows = $this->mergeWorkerRuntimeState($redisSnapshot['workers'], $refreshedAt);
        $envelopes = iterator_to_array($receiver->all(self::MESSAGE_LIMIT), false);
        $messages = $this->buildMessageRows($envelopes, $redisSnapshot['pendingById'], $refreshedAt);
        $activeLocks = $this->buildActiveLockRows();

        return [
            'ok' => true,
            'refreshedAt' => $refreshedAt->format(DATE_ATOM),
            'summary' => [
                'backlogCount' => $receiver->getMessageCount(),
                'inFlightCount' => $redisSnapshot['pendingCount'],
                'consumerCount' => count($workerRows),
                'activeLockCount' => count($activeLocks),
                'visibleMessageCount' => count($messages),
                'oldestMessageAgeMs' => $redisSnapshot['oldestMessageAgeMs'],
                'oldestPendingAgeMs' => $redisSnapshot['oldestPendingAgeMs'],
            ],
            'queue' => [
                'name' => 'fluxx',
                'stream' => $redisSnapshot['stream'],
                'group' => $redisSnapshot['group'],
            ],
            'workers' => $workerRows,
            'activeLocks' => $activeLocks,
            'messages' => $messages,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildActiveLockRows(): array
    {
        $rows = [];

        foreach ($this->workflowExecutionLockRepository->findActiveOrdered() as $lock) {
            $run = $this->workflowRunRepository->findOneByRunId($lock->ownerRunId());
            $workflowName = $lock->workflowName();

            if ($run !== null && $this->registry->has($workflowName)) {
                $workflowName = $this->registry->get($workflowName)->definition()->name();
            }

            $rows[] = [
                'workflowCode' => $lock->workflowName(),
                'workflowName' => $workflowName,
                'runId' => $lock->ownerRunId(),
                'status' => $run?->status()->value,
                'scope' => $lock->scope()->value,
                'lockKey' => $lock->lockKey(),
                'businessPartitionKey' => $lock->businessPartitionKey(),
                'acquiredAt' => $lock->acquiredAt()->format(DATE_ATOM),
            ];
        }

        return $rows;
    }

    private function createFluxxReceiver(): RedisReceiver
    {
        return new RedisReceiver(
            Connection::fromDsn($this->fluxxTransportDsn, []),
            $this->transportSerializer,
        );
    }

    /**
     * @param array<string, array{consumerName: string, idleMs: int, deliveryCount: int}> $pendingById
     * @param list<Envelope> $envelopes
     * @return list<array<string, mixed>>
     */
    private function buildMessageRows(array $envelopes, array $pendingById, DateTimeImmutable $refreshedAt): array
    {
        $messageRows = [];
        $runIds = [];

        foreach ($envelopes as $envelope) {
            $message = $envelope->getMessage();

            if (!$message instanceof RunWorkflowStepMessage) {
                continue;
            }

            $transportId = $envelope->last(TransportMessageIdStamp::class)?->getId();

            if ($transportId === null) {
                continue;
            }

            $runIds[] = $message->runId();
            $messageRows[] = [
                'id' => $transportId,
                'runId' => $message->runId(),
                'stepCode' => $message->stepCode(),
                'pending' => $pendingById[$transportId] ?? null,
            ];
        }

        $runMap = $this->workflowRunRepository->findByRunIdsIndexed($runIds);
        $stepRunsByRunId = $this->workflowStepRunRepository->findByWorkflowRunsGrouped(array_values($runMap));
        $definitions = [];
        $rows = [];

        foreach ($messageRows as $messageRow) {
            $run = $runMap[$messageRow['runId']] ?? null;
            $workflowCode = $run?->workflowName();

            if ($workflowCode !== null && !isset($definitions[$workflowCode]) && $this->registry->has($workflowCode)) {
                $definitions[$workflowCode] = $this->registry->get($workflowCode)->definition();
            }

            $definition = $workflowCode !== null ? ($definitions[$workflowCode] ?? null) : null;
            $stepDefinition = null;

            if ($definition !== null) {
                try {
                    $stepDefinition = $definition->step($messageRow['stepCode']);
                } catch (\InvalidArgumentException) {
                    $stepDefinition = null;
                }
            }

            $stepRun = null;

            foreach ($stepRunsByRunId[$messageRow['runId']] ?? [] as $candidate) {
                if ($candidate->stepName() === $messageRow['stepCode']) {
                    $stepRun = $candidate;
                    break;
                }
            }

            $typeCode = $stepDefinition?->type() ?? $stepRun?->stepType() ?? 'custom';
            $stepType = $this->stepTypeRegistry->get($typeCode);

            $rows[] = [
                'id' => $messageRow['id'],
                'state' => $messageRow['pending'] === null ? 'queued' : 'in_flight',
                'consumerName' => $messageRow['pending']['consumerName'] ?? null,
                'deliveryCount' => $messageRow['pending']['deliveryCount'] ?? null,
                'idleMs' => $messageRow['pending']['idleMs'] ?? null,
                'enqueuedAt' => $this->formatRedisMessageTimestamp($messageRow['id']),
                'ageMs' => $this->resolveRedisMessageAge($messageRow['id'], $refreshedAt),
                'workflowCode' => $workflowCode,
                'workflowName' => $definition?->name() ?? $workflowCode,
                'workflowStatus' => $run?->status()->value,
                'runId' => $messageRow['runId'],
                'sourceSystem' => $run?->sourceSystem(),
                'targetSystem' => $run?->targetSystem(),
                'stepCode' => $messageRow['stepCode'],
                'stepName' => $stepDefinition?->name() ?? $messageRow['stepCode'],
                'stepType' => $typeCode,
                'stepTypeLabel' => $stepType->label(),
                'stepTypeTone' => $stepType->toneClass(),
                'stepTypeToneStyle' => $stepType->toneStyle(),
                'stepStatus' => $stepRun?->status()->value ?? 'pending',
                'durationMs' => $stepRun?->durationMs(),
                'memoryPeakBytes' => $stepRun?->memoryPeakBytes(),
                'errorCategory' => is_string($stepRun?->errorPayload()['category'] ?? $run?->errorPayload()['category'] ?? null)
                    ? ($stepRun?->errorPayload()['category'] ?? $run?->errorPayload()['category'])
                    : null,
                'errorMessage' => $stepRun?->errorMessage() ?? $run?->errorMessage(),
            ];
        }

        return $rows;
    }

    /**
     * @return array{
     *     stream: string,
     *     group: string,
     *     pendingCount: int,
     *     oldestMessageAgeMs: ?int,
     *     oldestPendingAgeMs: ?int,
     *     workers: list<array<string, mixed>>,
     *     pendingById: array<string, array{consumerName: string, idleMs: int, deliveryCount: int}>
     * }
     */
    private function fetchRedisSnapshot(DateTimeImmutable $refreshedAt): array
    {
        $config = $this->parseRedisDsn($this->fluxxTransportDsn);
        $redis = $this->createRedisClient($config);
        $workers = $this->normalizeRedisMapList($redis->rawCommand('XINFO', 'CONSUMERS', $config['stream'], $config['group']) ?: []);
        $pendingSummary = $redis->rawCommand('XPENDING', $config['stream'], $config['group']) ?: [];
        $pendingDetails = $redis->rawCommand('XPENDING', $config['stream'], $config['group'], '-', '+', (string) self::MESSAGE_LIMIT) ?: [];
        $oldestMessage = $redis->xRange($config['stream'], '-', '+', 1) ?: [];
        $pendingById = [];
        $oldestPendingAgeMs = null;

        foreach ($pendingDetails as $detail) {
            if (!is_array($detail) || count($detail) < 4) {
                continue;
            }

            $messageId = (string) $detail[0];
            $idleMs = (int) $detail[2];

            $pendingById[$messageId] = [
                'consumerName' => (string) $detail[1],
                'idleMs' => $idleMs,
                'deliveryCount' => (int) $detail[3],
            ];

            if ($oldestPendingAgeMs === null || $idleMs > $oldestPendingAgeMs) {
                $oldestPendingAgeMs = $idleMs;
            }
        }

        $workerRows = [];

        foreach ($workers as $worker) {
            $idleMs = (int) ($worker['idle'] ?? 0);

            $workerRows[] = [
                'name' => (string) ($worker['name'] ?? 'unknown'),
                'pendingCount' => (int) ($worker['pending'] ?? 0),
                'idleMs' => $idleMs,
                'lastSeenAt' => $this->subtractMilliseconds($refreshedAt, $idleMs)->format(DATE_ATOM),
                'state' => $idleMs <= self::ACTIVE_CONSUMER_IDLE_THRESHOLD_MS ? 'active' : 'idle',
            ];
        }

        usort(
            $workerRows,
            static fn (array $left, array $right): int => [$right['pendingCount'], $left['idleMs']] <=> [$left['pendingCount'], $right['idleMs']],
        );

        $oldestMessageId = is_array($oldestMessage) ? (string) array_key_first($oldestMessage) : null;

        return [
            'stream' => $config['stream'],
            'group' => $config['group'],
            'pendingCount' => isset($pendingSummary[0]) ? (int) $pendingSummary[0] : 0,
            'oldestMessageAgeMs' => $oldestMessageId !== null ? $this->resolveRedisMessageAge($oldestMessageId, $refreshedAt) : null,
            'oldestPendingAgeMs' => $oldestPendingAgeMs,
            'workers' => $workerRows,
            'pendingById' => $pendingById,
        ];
    }

    /**
     * @param mixed $reply
     * @return list<array<string, mixed>>
     */
    private function normalizeRedisMapList(mixed $reply): array
    {
        if (!is_array($reply)) {
            return [];
        }

        $normalized = [];

        foreach ($reply as $item) {
            if (!is_array($item)) {
                continue;
            }

            $normalized[] = $this->normalizeRedisMap($item);
        }

        return $normalized;
    }

    /**
     * @param array<int|string, mixed> $item
     * @return array<string, mixed>
     */
    private function normalizeRedisMap(array $item): array
    {
        if (array_is_list($item)) {
            $normalized = [];
            $count = count($item);

            for ($index = 0; $index + 1 < $count; $index += 2) {
                if (!is_string($item[$index])) {
                    continue;
                }

                $normalized[$item[$index]] = $item[$index + 1];
            }

            return $normalized;
        }

        return $item;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createRedisClient(array $config): Redis
    {
        if (!class_exists(Redis::class)) {
            throw new RuntimeException('The Redis extension is required to monitor the fluxx transport.');
        }

        $redis = new Redis();

        if (!$redis->connect($config['host'], $config['port'], 2.0)) {
            throw new RuntimeException('Could not connect to Redis for runtime monitoring.');
        }

        if ($config['password'] !== null && $config['password'] !== '') {
            $redis->auth($config['password']);
        }

        if ($config['database'] !== null) {
            $redis->select($config['database']);
        }

        return $redis;
    }

    /**
     * @return array{host: string, port: int, password: ?string, database: ?int, stream: string, group: string}
     */
    private function parseRedisDsn(string $dsn): array
    {
        $parts = parse_url($dsn);

        if ($parts === false || !isset($parts['host'])) {
            throw new RuntimeException('The fluxx transport DSN is invalid.');
        }

        $pathParts = array_values(array_filter(explode('/', trim((string) ($parts['path'] ?? ''), '/'))));

        if (($pathParts[0] ?? null) === null || ($pathParts[1] ?? null) === null) {
            throw new RuntimeException('The fluxx transport DSN must define a stream and a consumer group.');
        }

        parse_str($parts['query'] ?? '', $query);

        return [
            'host' => (string) $parts['host'],
            'port' => isset($parts['port']) ? (int) $parts['port'] : 6379,
            'password' => isset($parts['pass']) ? (string) $parts['pass'] : null,
            'database' => isset($query['dbindex']) ? (int) $query['dbindex'] : null,
            'stream' => $pathParts[0],
            'group' => $pathParts[1],
        ];
    }

    private function resolveRedisMessageAge(string $messageId, DateTimeImmutable $refreshedAt): ?int
    {
        $timestamp = $this->extractRedisMessageTimestamp($messageId);

        if ($timestamp === null) {
            return null;
        }

        return max((int) ($refreshedAt->format('Uv') - $timestamp), 0);
    }

    private function formatRedisMessageTimestamp(string $messageId): ?string
    {
        $timestamp = $this->extractRedisMessageTimestamp($messageId);

        if ($timestamp === null) {
            return null;
        }

        return DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $timestamp / 1000))?->format(DATE_ATOM);
    }

    private function extractRedisMessageTimestamp(string $messageId): ?int
    {
        [$milliseconds] = explode('-', $messageId, 2);

        return ctype_digit($milliseconds) ? (int) $milliseconds : null;
    }

    /**
     * @param list<array<string, mixed>> $redisWorkers
     * @return list<array<string, mixed>>
     */
    private function mergeWorkerRuntimeState(array $redisWorkers, DateTimeImmutable $refreshedAt): array
    {
        $workerStates = $this->runtimeWorkerStateRepository->findIndexedByTransportName('fluxx');
        $workflowDefinitions = [];
        $workerRows = [];
        $seenWorkerNames = [];

        foreach ($redisWorkers as $worker) {
            $workerState = isset($worker['name']) ? ($workerStates[$worker['name']] ?? null) : null;
            $workerRows[] = $this->buildWorkerRow($worker, $workerState, $workflowDefinitions, $refreshedAt);

            if (isset($worker['name'])) {
                $seenWorkerNames[(string) $worker['name']] = true;
            }
        }

        foreach ($workerStates as $workerName => $workerState) {
            if (
                isset($seenWorkerNames[$workerName])
                || $workerState->status() === 'stopped'
                || $this->resolveDateAgeMs($workerState->lastHeartbeatAt(), $refreshedAt) > self::ORPHAN_WORKER_HEARTBEAT_TTL_MS
            ) {
                continue;
            }

            $workerRows[] = $this->buildWorkerRow([
                'name' => $workerName,
                'state' => $workerState->status(),
                'pendingCount' => 0,
                'idleMs' => $this->resolveDateAgeMs($workerState->lastHeartbeatAt(), $refreshedAt),
                'lastSeenAt' => $workerState->lastHeartbeatAt()->format(DATE_ATOM),
            ], $workerState, $workflowDefinitions, $refreshedAt);
        }

        usort(
            $workerRows,
            static fn (array $left, array $right): int => [
                $right['state'] === 'processing',
                $right['pendingCount'],
                $left['name'],
            ] <=> [
                $left['state'] === 'processing',
                $left['pendingCount'],
                $right['name'],
            ],
        );

        return $workerRows;
    }

    /**
     * @param array<string, mixed> $redisWorker
     * @param array<string, mixed> $workflowDefinitions
     * @return array<string, mixed>
     */
    private function buildWorkerRow(
        array $redisWorker,
        ?RuntimeWorkerState $workerState,
        array &$workflowDefinitions,
        DateTimeImmutable $refreshedAt,
    ): array {
        $workflowDefinition = null;
        $stepDefinition = null;
        $workflowCode = $workerState?->workflowCode();
        $stepCode = $workerState?->stepCode();

        if ($workflowCode !== null && $this->registry->has($workflowCode)) {
            $workflowDefinitions[$workflowCode] ??= $this->registry->get($workflowCode)->definition();
            $workflowDefinition = $workflowDefinitions[$workflowCode];
        }

        if ($workflowDefinition !== null && $stepCode !== null) {
            try {
                $stepDefinition = $workflowDefinition->step($stepCode);
            } catch (\InvalidArgumentException) {
                $stepDefinition = null;
            }
        }

        $stepTypeCode = $stepDefinition?->type();
        $stepType = $stepTypeCode !== null ? $this->stepTypeRegistry->get($stepTypeCode) : null;

        return [
            'name' => (string) ($redisWorker['name'] ?? $workerState?->workerName() ?? '-'),
            'state' => $workerState?->status() === 'processing' ? 'processing' : (string) ($redisWorker['state'] ?? $workerState?->status() ?? 'idle'),
            'pendingCount' => (int) ($redisWorker['pendingCount'] ?? 0),
            'idleMs' => isset($redisWorker['idleMs'])
                ? (int) $redisWorker['idleMs']
                : ($workerState !== null ? $this->resolveDateAgeMs($workerState->lastHeartbeatAt(), $refreshedAt) : null),
            'lastSeenAt' => $workerState?->lastHeartbeatAt()->format(DATE_ATOM) ?? ($redisWorker['lastSeenAt'] ?? null),
            'host' => $workerState?->host(),
            'pid' => $workerState?->pid(),
            'receiverName' => $workerState?->receiverName(),
            'memoryBytes' => $workerState?->memoryBytes(),
            'currentMessageClass' => $workerState?->currentMessageClass(),
            'currentTransportMessageId' => $workerState?->currentTransportMessageId(),
            'workflowCode' => $workflowCode,
            'workflowName' => $workflowDefinition?->name() ?? $workflowCode,
            'runId' => $workerState?->runId(),
            'stepCode' => $stepCode,
            'stepName' => $stepDefinition?->name() ?? $stepCode,
            'stepType' => $stepTypeCode,
            'stepTypeLabel' => $stepType?->label(),
            'stepTypeTone' => $stepType?->toneClass(),
            'stepTypeToneStyle' => $stepType?->toneStyle(),
            'processingStartedAt' => $workerState?->startedProcessingAt()?->format(DATE_ATOM),
            'processingDurationMs' => $workerState?->startedProcessingAt() !== null
                ? $this->resolveDateAgeMs($workerState->startedProcessingAt(), $refreshedAt)
                : null,
        ];
    }

    private function resolveDateAgeMs(DateTimeImmutable $dateTime, DateTimeImmutable $refreshedAt): int
    {
        return max((int) $refreshedAt->format('Uv') - (int) $dateTime->format('Uv'), 0);
    }

    private function subtractMilliseconds(DateTimeImmutable $dateTime, int $milliseconds): DateTimeImmutable
    {
        $targetTimestamp = ((int) $dateTime->format('Uv')) - max($milliseconds, 0);

        return DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $targetTimestamp / 1000)) ?: $dateTime;
    }
}
