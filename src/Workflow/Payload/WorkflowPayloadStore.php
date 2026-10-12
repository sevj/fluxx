<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Payload;

use Doctrine\ORM\EntityManagerInterface;
use Fluxx\Entity\WorkflowPayload;
use Fluxx\Entity\WorkflowPayloadContent;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;
use JsonException;
use RuntimeException;

final readonly class WorkflowPayloadStore implements WorkflowPayloadStoreInterface
{
    private const SNAPSHOT_VERSION = 1;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $records
     * @param array<string, mixed> $metadata
     *
     * @throws JsonException
     */
    public function storeStepInput(
        WorkflowRun $workflowRun,
        WorkflowStepRun $sourceStepRun,
        string $targetStepType,
        string $targetStepName,
        array $records,
        int $recordCount,
        int $sequence = 1,
        array $metadata = [],
    ): WorkflowPayload {
        $payload = [
            'version' => self::SNAPSHOT_VERSION,
            'workflow_code' => $workflowRun->workflowName(),
            'run_id' => $workflowRun->runId(),
            'records' => $records,
            'metadata' => $metadata,
        ];

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $contentHash = hash('sha256', $json);

        $payloadContent = $this->resolvePayloadContent(
            $workflowRun,
            $sourceStepRun,
            $contentHash,
            $recordCount,
            $json,
        );

        $workflowPayload = new WorkflowPayload(
            workflowRun: $workflowRun,
            sourceStepRun: $sourceStepRun,
            payloadContent: $payloadContent,
            targetStepType: $targetStepType,
            targetStepName: $targetStepName,
            sequence: $sequence,
            metadata: $metadata,
        );

        $this->entityManager->persist($workflowPayload);

        return $workflowPayload;
    }

    /**
     * Reuses an existing payload content blob when records with the same hash
     * were already stored for this workflow run (deduplication across fan-out
     * downstreams that receive identical records).
     *
     * @throws JsonException
     */
    private function resolvePayloadContent(
        WorkflowRun $workflowRun,
        WorkflowStepRun $sourceStepRun,
        string $contentHash,
        int $recordCount,
        string $json,
    ): WorkflowPayloadContent {
        $existing = $this->entityManager->getRepository(WorkflowPayloadContent::class)
            ->findOneBy([
                'workflowRun' => $workflowRun,
                'contentHash' => $contentHash,
            ]);

        if ($existing !== null) {
            return $existing;
        }

        $compressed = gzencode($json, 6);

        if ($compressed === false) {
            throw new RuntimeException('Unable to compress workflow payload.');
        }

        $storedContent = base64_encode($compressed);

        $payloadContent = new WorkflowPayloadContent(
            workflowRun: $workflowRun,
            sourceStepRun: $sourceStepRun,
            contentHash: $contentHash,
            format: 'json',
            compression: 'gzip',
            storageMode: 'database',
            content: $storedContent,
            recordCount: $recordCount,
            rawSize: strlen($json),
            storedSize: strlen($storedContent),
        );

        $this->entityManager->persist($payloadContent);

        return $payloadContent;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    public function load(WorkflowPayload $workflowPayload): array
    {
        $decoded = base64_decode($workflowPayload->content(), true);

        if ($decoded === false) {
            throw new RuntimeException('Unable to decode workflow payload.');
        }

        $json = @gzdecode($decoded);

        if ($json === false) {
            throw new RuntimeException('Unable to decompress workflow payload.');
        }

        /** @var array<string, mixed> $payload */
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }
}
