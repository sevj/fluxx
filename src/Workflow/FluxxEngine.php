<?php

declare(strict_types=1);

namespace Fluxx\Workflow;

use Doctrine\ORM\EntityManagerInterface;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Workflow\Error\WorkflowErrorPayloadFactory;
use Fluxx\Workflow\Lock\WorkflowExecutionLockManagerInterface;
use Fluxx\Workflow\MessageHandler\StepMessageDispatcher;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

final readonly class FluxxEngine
{
    public function __construct(
        private SynchronizationRegistry $registry,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
        private WorkflowExecutionLockManagerInterface $workflowExecutionLockManager,
        private WorkflowErrorPayloadFactory $workflowErrorPayloadFactory,
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function run(
        string $workflowCode,
        string $trigger = 'manual',
        ?string $batchId = null,
        array $metadata = [],
    ): string {
        $workflow = $this->registry->get($workflowCode);
        $definition = $workflow->definition();
        $runId = self::generateRunId();

        $workflowRun = new WorkflowRun(
            runId: $runId,
            workflowName: $definition->code(),
            sourceSystem: $definition->sourceSystem(),
            targetSystem: $definition->targetSystem(),
            trigger: $trigger,
            batchId: $batchId,
            metadata: $metadata,
        );
        $workflowRun->markRunning();
        $this->workflowExecutionLockManager->acquire($workflowRun, $definition);

        $this->entityManager->persist($workflowRun);
        $this->entityManager->flush();

        try {
            foreach ($definition->rootSteps() as $stepDefinition) {
                StepMessageDispatcher::dispatch(
                    $this->messageBus,
                    $runId,
                    $stepDefinition->code(),
                );
            }
        } catch (Throwable $throwable) {
            $errorPayload = $this->workflowErrorPayloadFactory->fromThrowable($throwable);
            $workflowRun->markFailed($throwable->getMessage(), errorPayload: $errorPayload);
            $this->workflowExecutionLockManager->releaseForRun($workflowRun, 'dispatch_failed');
            $this->entityManager->flush();

            throw $throwable;
        }

        return $runId;
    }

    private static function generateRunId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
