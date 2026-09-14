<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Relaunch;

use Doctrine\ORM\EntityManagerInterface;
use Fluxx\Entity\Enum\WorkflowRunStatus;
use Fluxx\Entity\WorkflowPayload;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;
use Fluxx\Repository\WorkflowPayloadLookupInterface;
use Fluxx\Repository\WorkflowRunLookupInterface;
use Fluxx\Repository\WorkflowStepRunLookupInterface;
use Fluxx\Workflow\Lock\WorkflowExecutionLockManagerInterface;
use Fluxx\Workflow\MessageHandler\StepMessageDispatcher;
use Fluxx\Workflow\Payload\WorkflowPayloadStoreInterface;
use Fluxx\Workflow\SynchronizationRegistry;
use RuntimeException;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

final readonly class WorkflowRelaunchService
{
    public function __construct(
        private SynchronizationRegistry $registry,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
        private WorkflowRunLookupInterface $workflowRunRepository,
        private WorkflowStepRunLookupInterface $workflowStepRunRepository,
        private WorkflowPayloadLookupInterface $workflowPayloadRepository,
        private WorkflowPayloadStoreInterface $workflowPayloadStore,
        private WorkflowExecutionLockManagerInterface $workflowExecutionLockManager,
        private WorkflowRelaunchPlanner $workflowRelaunchPlanner,
    ) {
    }

    public function relaunch(
        string $originalRunId,
        WorkflowRelaunchMode $mode = WorkflowRelaunchMode::Full,
        ?string $restartStepCode = null,
        string $trigger = 'manual',
        ?string $reason = null,
        ?string $operatorUser = null,
        bool $force = false,
    ): string {
        $originalRun = $this->workflowRunRepository->findOneByRunId($originalRunId);

        if ($originalRun === null) {
            throw new RuntimeException(sprintf('Workflow run "%s" was not found.', $originalRunId));
        }

        if (!$force && !in_array($originalRun->status(), [
            WorkflowRunStatus::Completed,
            WorkflowRunStatus::Failed,
            WorkflowRunStatus::PartiallyFailed,
            WorkflowRunStatus::Cancelled,
        ], true)) {
            throw RunStillActiveException::fromStatus($originalRun->runId(), $originalRun->status());
        }

        $workflow = $this->registry->get($originalRun->workflowName());
        $definition = $workflow->definition();
        $plan = $this->workflowRelaunchPlanner->plan($definition, $mode, $restartStepCode);
        $runId = self::generateRunId();
        $workflowRun = new WorkflowRun(
            runId: $runId,
            workflowName: $definition->code(),
            sourceSystem: $definition->sourceSystem(),
            targetSystem: $definition->targetSystem(),
            trigger: 'relaunch',
            batchId: $originalRun->batchId(),
            metadata: $this->buildRunMetadata(
                originalRun: $originalRun,
                plan: $plan,
                trigger: $trigger,
                reason: $reason,
                operatorUser: $operatorUser,
            ),
        );
        $workflowRun->markRelaunched();
        $this->workflowExecutionLockManager->acquire($workflowRun, $definition);

        $this->entityManager->persist($workflowRun);

        $originalStepRuns = $this->indexLatestStepRuns($originalRun);
        $clonedStepRuns = $this->clonePreservedStepRuns($workflowRun, $plan, $originalRun, $originalStepRuns);
        $this->createResetTargetStepRuns($workflowRun, $plan, $originalRun, $originalStepRuns, $clonedStepRuns);
        $this->cloneEntryPayloads($workflowRun, $plan, $originalRun, $clonedStepRuns);
        $this->entityManager->flush();

        try {
            foreach ($plan->entryStepCodes() as $entryStepCode) {
                StepMessageDispatcher::dispatch($this->messageBus, $runId, $entryStepCode);
            }
        } catch (Throwable $throwable) {
            $workflowRun->markFailed($throwable->getMessage());
            $this->workflowExecutionLockManager->releaseForRun($workflowRun, 'dispatch_failed');
            $this->entityManager->flush();

            throw $throwable;
        }

        return $runId;
    }

    /**
     * @return array<string, WorkflowStepRun>
     */
    private function indexLatestStepRuns(WorkflowRun $workflowRun): array
    {
        $indexed = [];

        foreach ($this->workflowStepRunRepository->findByWorkflowRunOrdered($workflowRun) as $stepRun) {
            $indexed[$stepRun->stepName()] = $stepRun;
        }

        return $indexed;
    }

    /**
     * @param array<string, WorkflowStepRun> $originalStepRuns
     * @return array<string, WorkflowStepRun>
     */
    private function clonePreservedStepRuns(
        WorkflowRun $workflowRun,
        WorkflowRelaunchPlan $plan,
        WorkflowRun $originalRun,
        array $originalStepRuns,
    ): array {
        $cloned = [];
        $definition = $this->registry->get($workflowRun->workflowName())->definition();

        foreach ($plan->preservedStepCodes() as $stepCode) {
            $originalStepRun = $originalStepRuns[$stepCode] ?? null;

            if ($originalStepRun === null) {
                throw new RuntimeException(sprintf(
                    'Unable to relaunch workflow "%s": preserved step "%s" was not found on run "%s".',
                    $workflowRun->workflowName(),
                    $stepCode,
                    $originalRun->runId(),
                ));
            }

            $clonedStepRun = new WorkflowStepRun(
                workflowRun: $workflowRun,
                stepType: $originalStepRun->stepType(),
                stepName: $originalStepRun->stepName(),
                position: $definition->positionOf($originalStepRun->stepName()),
                metadata: $this->buildPreservedStepMetadata($originalStepRun, $originalRun),
            );
            $clonedStepRun->markRunning($originalStepRun->startedAt());
            $clonedStepRun->markCompleted(
                processedCount: $originalStepRun->processedCount(),
                successCount: $originalStepRun->successCount(),
                errorCount: $originalStepRun->errorCount(),
                durationMs: $originalStepRun->durationMs(),
                memoryPeakBytes: $originalStepRun->memoryPeakBytes(),
                finishedAt: $originalStepRun->finishedAt(),
            );
            $this->entityManager->persist($clonedStepRun);
            $cloned[$stepCode] = $clonedStepRun;
        }

        return $cloned;
    }

    /**
     * @param array<string, WorkflowStepRun> $originalStepRuns
     * @param array<string, WorkflowStepRun> $clonedStepRuns
     */
    private function createResetTargetStepRuns(
        WorkflowRun $workflowRun,
        WorkflowRelaunchPlan $plan,
        WorkflowRun $originalRun,
        array $originalStepRuns,
        array &$clonedStepRuns,
    ): void {
        $definition = $this->registry->get($workflowRun->workflowName())->definition();

        foreach ($plan->targetStepCodes() as $stepCode) {
            $stepDefinition = $definition->step($stepCode);
            $originalStepRun = $originalStepRuns[$stepCode] ?? null;
            $stepRun = new WorkflowStepRun(
                workflowRun: $workflowRun,
                stepType: $stepDefinition->type(),
                stepName: $stepCode,
                position: $definition->positionOf($stepCode),
                metadata: $this->buildTargetStepMetadata($plan, $originalRun, $originalStepRun),
            );
            $stepRun->markRelaunched();
            $this->entityManager->persist($stepRun);
            $clonedStepRuns[$stepCode] = $stepRun;
        }
    }

    /**
     * @param array<string, WorkflowStepRun> $clonedStepRuns
     */
    private function cloneEntryPayloads(
        WorkflowRun $workflowRun,
        WorkflowRelaunchPlan $plan,
        WorkflowRun $originalRun,
        array $clonedStepRuns,
    ): void {
        if ($plan->mode() === WorkflowRelaunchMode::Full) {
            return;
        }

        foreach ($plan->entryStepCodes() as $entryStepCode) {
            foreach ($this->workflowPayloadRepository->findByWorkflowRunAndTargetStepNameOrdered($originalRun, $entryStepCode) as $payload) {
                $sourceStepCode = $payload->sourceStepRun()->stepName();
                $sourceStepRun = $clonedStepRuns[$sourceStepCode] ?? null;

                if ($sourceStepRun === null) {
                    throw new RuntimeException(sprintf(
                        'Unable to relaunch workflow "%s": payload source step "%s" is missing from relaunch context.',
                        $workflowRun->workflowName(),
                        $sourceStepCode,
                    ));
                }

                $snapshot = $this->workflowPayloadStore->load($payload);
                $metadata = $snapshot['metadata'] ?? $payload->metadata();

                if (!is_array($metadata)) {
                    $metadata = $payload->metadata();
                }

                $metadata['relaunch'] = [
                    'status' => 'reused_input',
                    'original_run_id' => $originalRun->runId(),
                    'original_payload_id' => $payload->id(),
                    'entry_step_code' => $entryStepCode,
                ];

                $records = $snapshot['records'] ?? [];

                $this->workflowPayloadStore->storeStepInput(
                    workflowRun: $workflowRun,
                    sourceStepRun: $sourceStepRun,
                    targetStepType: $payload->targetStepType(),
                    targetStepName: $payload->targetStepName(),
                    records: is_array($records) ? $records : [],
                    recordCount: $payload->recordCount(),
                    sequence: $payload->sequence(),
                    metadata: $metadata,
                );
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRunMetadata(
        WorkflowRun $originalRun,
        WorkflowRelaunchPlan $plan,
        string $trigger,
        ?string $reason,
        ?string $operatorUser,
    ): array {
        $metadata = $originalRun->metadata();
        unset($metadata['error'], $metadata['relaunch']);
        $metadata['relaunch'] = [
            'original_run_id' => $originalRun->runId(),
            'trigger' => $trigger,
            'reason' => $reason,
            'restart_step_code' => $plan->entryStepCodes()[0] ?? null,
            'operator_user' => $operatorUser,
            'mode' => $plan->mode()->value,
            'target_step_codes' => $plan->targetStepCodes(),
            'strategy' => [
                'payloads' => $plan->mode() === WorkflowRelaunchMode::Full ? 'fresh' : 'reuse_existing_inputs',
                'downstream_payloads' => 'regenerate',
                'downstream_step_runs' => 'reset',
                'history' => 'preserved',
            ],
        ];

        return $metadata;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPreservedStepMetadata(WorkflowStepRun $originalStepRun, WorkflowRun $originalRun): array
    {
        $metadata = $originalStepRun->metadata();
        unset($metadata['error']);
        $metadata['relaunch'] = [
            'status' => 'preserved',
            'original_run_id' => $originalRun->runId(),
            'original_step_run_id' => $originalStepRun->id(),
        ];

        return $metadata;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTargetStepMetadata(
        WorkflowRelaunchPlan $plan,
        WorkflowRun $originalRun,
        ?WorkflowStepRun $originalStepRun,
    ): array {
        return [
            'relaunch' => [
                'status' => 'reset',
                'mode' => $plan->mode()->value,
                'original_run_id' => $originalRun->runId(),
                'original_step_run_id' => $originalStepRun?->id(),
            ],
        ];
    }

    private static function generateRunId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
