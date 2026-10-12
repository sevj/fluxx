<?php

declare(strict_types=1);

namespace Fluxx\Workflow;

use Doctrine\ORM\EntityManagerInterface;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Workflow\Error\WorkflowErrorPayloadFactory;
use Fluxx\Workflow\Lock\WorkflowExecutionLockManagerInterface;
use Fluxx\Workflow\Runtime\FluxxRuntime;
use Fluxx\Workflow\Result\WorkflowStepResult;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final readonly class SynchronousFluxxEngine implements FluxxEngineInterface, SynchronousFluxxEngineInterface
{
    public function __construct(
        private SynchronizationRegistry $registry,
        private EntityManagerInterface $entityManager,
        private WorkflowExecutionLockManagerInterface $workflowExecutionLockManager,
        private WorkflowErrorPayloadFactory $workflowErrorPayloadFactory,
        private FluxxRuntime $runtime,
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
        return $this->execute($workflowCode, $trigger, $batchId, $metadata, null)->runId;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function runWithResult(
        string $workflowCode,
        string $trigger = 'manual',
        ?string $batchId = null,
        array $metadata = [],
        ?OutputInterface $output = null,
    ): SynchronousWorkflowResult {
        return $this->execute($workflowCode, $trigger, $batchId, $metadata, $output);
    }

    private function execute(
        string $workflowCode,
        string $trigger,
        ?string $batchId,
        array $metadata,
        ?OutputInterface $output = null,
    ): SynchronousWorkflowResult {
        $definition = $this->registry->get($workflowCode)->definition();
        $runId = bin2hex(random_bytes(16));

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
        $workflowRun->markSynchronous();

        $this->workflowExecutionLockManager->acquire($workflowRun, $definition);

        $this->entityManager->persist($workflowRun);
        $this->entityManager->flush();

        $leafCodes = [];
        foreach ($this->leafSteps($definition) as $leaf) {
            $leafCodes[$leaf->code()] = true;
        }

        $records = [];
        $lastLeafWasCaptured = false;
        $totalSteps = count($definition->steps());
        $stepCounter = 0;

        try {
            $queue = array_map(
                static fn (WorkflowStepDefinition $step): string => $step->code(),
                $definition->rootSteps(),
            );

            while ($queue !== []) {
                $stepCounter++;
                $stepCode = array_shift($queue);
                $stepDefinition = $definition->step($stepCode);

                if ($output instanceof OutputInterface) {
                    $output->write(sprintf(
                        '  Step %d/%d: %s (%s)…',
                        $stepCounter,
                        $totalSteps,
                        $stepDefinition->name(),
                        $stepCode,
                    ));
                }

                $startNs = hrtime(true);

                try {
                    $nextSteps = $this->runtime->runStep($runId, $stepCode);
                } catch (Throwable $throwable) {
                    if ($output instanceof OutputInterface) {
                        $output->writeln(sprintf(
                            ' FAILED: %s',
                            $throwable->getMessage(),
                        ));
                    }
                    throw $throwable;
                }

                $elapsedSeconds = (hrtime(true) - $startNs) / 1_000_000_000.0;

                if ($output instanceof OutputInterface) {
                    $result = $this->runtime->lastResult();
                    if ($result instanceof WorkflowStepResult) {
                        $errorsLabel = $result->errorCount() > 0
                            ? sprintf(', %d errors', $result->errorCount())
                            : '';
                        $output->writeln(sprintf(
                            ' %d processed%s, %.2fs',
                            $result->processedCount(),
                            $errorsLabel,
                            $elapsedSeconds,
                        ));
                    } else {
                        $output->writeln(sprintf(' done, %.2fs', $elapsedSeconds));
                    }
                }

                if (isset($leafCodes[$stepCode])) {
                    $result = $this->runtime->lastResult();
                    if ($result instanceof WorkflowStepResult) {
                        $resultRecords = $result->records();
                        if (array_is_list($resultRecords)) {
                            foreach ($resultRecords as $record) {
                                $records[] = $record;
                            }
                        } else {
                            $records[] = $resultRecords;
                        }
                        $lastLeafWasCaptured = true;
                    }
                }

                foreach ($nextSteps as $nextStep) {
                    $queue[] = $nextStep['code'];
                }
            }
        } catch (Throwable $throwable) {
            $errorPayload = $this->workflowErrorPayloadFactory->fromThrowable($throwable);
            $workflowRun->markFailed($throwable->getMessage(), errorPayload: $errorPayload);
            $this->entityManager->flush();

            throw $throwable;
        }

        if (!$lastLeafWasCaptured && $records === []) {
            $result = $this->runtime->lastResult();
            if ($result instanceof WorkflowStepResult) {
                $records = $result->records();
            }
        }

        return new SynchronousWorkflowResult(runId: $runId, records: $records);
    }

    /**
     * @return list<WorkflowStepDefinition>
     */
    private function leafSteps(WorkflowDefinition $definition): array
    {
        $dependees = [];
        foreach ($definition->steps() as $step) {
            foreach ($step->dependsOn() as $dependency) {
                $dependees[$dependency] = true;
            }
        }

        return array_values(array_filter(
            $definition->steps(),
            static fn (WorkflowStepDefinition $step): bool => !isset($dependees[$step->code()]),
        ));
    }
}
