<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Payload;

use Doctrine\ORM\EntityManagerInterface;
use Fluxx\Entity\WorkflowPayload;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;
use JsonException;

final readonly class WorkflowPayloadStore
{
    private const SNAPSHOT_VERSION = 1;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
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
            'target_step_type' => $targetStepType,
            'target_step_code' => $targetStepName,
            'records' => $records,
            'metadata' => $metadata,
        ];

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $compressed = gzencode($json, 6);

        if ($compressed === false) {
            throw new \RuntimeException('Unable to compress workflow payload.');
        }

        $storedContent = base64_encode($compressed);

        $workflowPayload = new WorkflowPayload(
            workflowRun: $workflowRun,
            sourceStepRun: $sourceStepRun,
            targetStepType: $targetStepType,
            targetStepName: $targetStepName,
            sequence: $sequence,
            format: 'json',
            compression: 'gzip',
            storageMode: 'database',
            content: $storedContent,
            contentHash: hash('sha256', $json),
            recordCount: $recordCount,
            rawSize: strlen($json),
            storedSize: strlen($storedContent),
            metadata: $metadata,
        );

        $this->entityManager->persist($workflowPayload);

        return $workflowPayload;
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
            throw new \RuntimeException('Unable to decode workflow payload.');
        }

        $json = gzdecode($decoded);

        if ($json === false) {
            throw new \RuntimeException('Unable to decompress workflow payload.');
        }

        /** @var array<string, mixed> $payload */
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }
}
