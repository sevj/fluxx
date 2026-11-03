<?php

declare(strict_types=1);

namespace Fluxx\Tests\Workflow\Relaunch;

use Doctrine\ORM\EntityManagerInterface;
use Fluxx\Entity\Enum\WorkflowRunStatus;
use Fluxx\Entity\Enum\WorkflowStepType;
use Fluxx\Entity\WorkflowPayload;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;
use Fluxx\Repository\WorkflowPayloadLookupInterface;
use Fluxx\Repository\WorkflowRunLookupInterface;
use Fluxx\Repository\WorkflowStepRunLookupInterface;
use Fluxx\Workflow\Lock\WorkflowExecutionLockManagerInterface;
use Fluxx\Workflow\Payload\WorkflowPayloadStoreInterface;
use Fluxx\Workflow\Relaunch\RunStillActiveException;
use Fluxx\Workflow\Relaunch\WorkflowRelaunchMode;
use Fluxx\Workflow\Relaunch\WorkflowRelaunchPlanner;
use Fluxx\Workflow\Relaunch\WorkflowRelaunchService;
use Fluxx\Workflow\Step\JoinStep;
use Fluxx\Workflow\SynchronizationRegistry;
use Fluxx\Workflow\WorkflowDefinition;
use Fluxx\Workflow\WorkflowInterface;
use Fluxx\Workflow\WorkflowStepDefinition;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[AllowMockObjectsWithoutExpectations]
final class WorkflowRelaunchServiceTest extends TestCase
{
    private WorkflowRunLookupInterface&MockObject $workflowRunRepository;

    private WorkflowRelaunchService $service;

    protected function setUp(): void
    {
        $this->workflowRunRepository = $this->createMock(WorkflowRunLookupInterface::class);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $messageBus = $this->createMock(MessageBusInterface::class);
        $stepRunRepository = $this->createMock(WorkflowStepRunLookupInterface::class);
        $payloadRepository = $this->createMock(WorkflowPayloadLookupInterface::class);
        $payloadStore = $this->createMock(WorkflowPayloadStoreInterface::class);
        $lockManager = $this->createMock(WorkflowExecutionLockManagerInterface::class);

        $this->service = new WorkflowRelaunchService(
            registry: new SynchronizationRegistry([]),
            entityManager: $entityManager,
            messageBus: $messageBus,
            workflowRunRepository: $this->workflowRunRepository,
            workflowStepRunRepository: $stepRunRepository,
            workflowPayloadRepository: $payloadRepository,
            workflowPayloadStore: $payloadStore,
            workflowExecutionLockManager: $lockManager,
            workflowRelaunchPlanner: new WorkflowRelaunchPlanner(),
        );
    }

    /**
     * @return iterable<string, array{0: WorkflowRunStatus}>
     */
    public static function nonTerminalStatuses(): iterable
    {
        yield 'pending' => [WorkflowRunStatus::Pending];
        yield 'running' => [WorkflowRunStatus::Running];
        yield 'retrying' => [WorkflowRunStatus::Retrying];
        yield 'relaunched' => [WorkflowRunStatus::Relaunched];
    }

    #[Test]
    #[DataProvider('nonTerminalStatuses')]
    public function it_refuses_to_relaunch_a_non_terminal_run(WorkflowRunStatus $status): void
    {
        $this->workflowRunRepository->method('findOneByRunId')->willReturn($this->createRun($status));

        $this->expectException(RunStillActiveException::class);

        $this->service->relaunch('run-1');
    }

    #[Test]
    public function it_allows_relaunching_a_non_terminal_run_when_forced(): void
    {
        $run = $this->createRun(WorkflowRunStatus::Running);
        $this->workflowRunRepository->method('findOneByRunId')->willReturn($run);

        try {
            $this->service->relaunch('run-1', force: true);
        } catch (RunStillActiveException $exception) {
            self::fail(sprintf('The force flag was ignored: %s', $exception->getMessage()));
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('is not registered', $exception->getMessage());

            return;
        }

        self::fail('A downstream failure was expected because the workflow is not registered.');
    }

    #[Test]
    public function it_keeps_the_outputs_a_relaunched_step_reads_from_preserved_ancestors(): void
    {
        $original = $this->createRun(WorkflowRunStatus::Completed);
        $this->workflowRunRepository->method('findOneByRunId')->willReturn($original);

        $handler = new JoinStep('h', 'H');
        $workflow = $this->createStub(WorkflowInterface::class);
        $workflow->method('definition')->willReturn(new WorkflowDefinition('fixture', 'Fixture', 'a', 'b', [
            new WorkflowStepDefinition('first', 'First', WorkflowStepType::Read, $handler),
            new WorkflowStepDefinition('second', 'Second', WorkflowStepType::Read, $handler, ['first']),
            new WorkflowStepDefinition('third', 'Third', WorkflowStepType::Read, $handler, ['second'], reads: ['first']),
        ]));

        $stepRuns = [];
        foreach (['first', 'second', 'third'] as $position => $code) {
            $stepRun = new WorkflowStepRun($original, 'read', $code, $position + 1);
            $stepRun->markRunning();
            $stepRun->markCompleted(processedCount: 1, successCount: 1, errorCount: 0);
            $stepRuns[$code] = $stepRun;
        }

        $entryPayload = $this->payload($stepRuns['second'], 'third');
        $firstOutput = $this->payload($stepRuns['first'], 'second');

        $stepRunRepository = $this->createStub(WorkflowStepRunLookupInterface::class);
        $stepRunRepository->method('findByWorkflowRunOrdered')->willReturn(array_values($stepRuns));
        $payloadRepository = $this->createStub(WorkflowPayloadLookupInterface::class);
        $payloadRepository->method('findByWorkflowRunAndTargetStepNameOrdered')->willReturn([$entryPayload]);
        $payloadRepository->method('findBySourceStepRunOrdered')->willReturn([$firstOutput]);
        $payloadStore = $this->createMock(WorkflowPayloadStoreInterface::class);
        $payloadStore->method('load')->willReturn(['records' => [['id' => 1]], 'metadata' => []]);

        $stored = [];
        $payloadStore->method('storeStepInput')->willReturnCallback(function (...$arguments) use (&$stored, $entryPayload): WorkflowPayload {
            $stored[] = $arguments[1]->stepName() . '>' . $arguments[3] . ':' . $arguments[7]['relaunch']['status'];

            return $entryPayload;
        });

        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturn(new Envelope(new \stdClass()));

        $service = new WorkflowRelaunchService(
            registry: new SynchronizationRegistry([$workflow]),
            entityManager: $this->createStub(EntityManagerInterface::class),
            messageBus: $messageBus,
            workflowRunRepository: $this->workflowRunRepository,
            workflowStepRunRepository: $stepRunRepository,
            workflowPayloadRepository: $payloadRepository,
            workflowPayloadStore: $payloadStore,
            workflowExecutionLockManager: $this->createStub(WorkflowExecutionLockManagerInterface::class),
            workflowRelaunchPlanner: new WorkflowRelaunchPlanner(),
        );

        $service->relaunch('run-1', WorkflowRelaunchMode::Step, 'third');

        self::assertSame(['second>third:reused_input', 'first>second:reused_ancestor_output'], $stored);
    }

    private function payload(WorkflowStepRun $source, string $target): WorkflowPayload
    {
        $payload = $this->createStub(WorkflowPayload::class);
        $payload->method('sourceStepRun')->willReturn($source);
        $payload->method('targetStepName')->willReturn($target);
        $payload->method('targetStepType')->willReturn('read');
        $payload->method('sequence')->willReturn(1);
        $payload->method('metadata')->willReturn([]);

        return $payload;
    }

    private function createRun(WorkflowRunStatus $status): WorkflowRun
    {
        $run = new WorkflowRun(
            runId: 'run-1',
            workflowName: 'fixture',
            sourceSystem: 'src',
            targetSystem: 'tgt',
            trigger: 'manual',
        );

        match ($status) {
            WorkflowRunStatus::Pending => null,
            WorkflowRunStatus::Running => $run->markRunning(),
            WorkflowRunStatus::Retrying => $run->markRetrying(),
            WorkflowRunStatus::Relaunched => $run->markRelaunched(),
            WorkflowRunStatus::Completed => $run->markCompleted(),
            WorkflowRunStatus::Failed => $run->markFailed(),
            WorkflowRunStatus::Cancelled => $run->markCancelled(),
            WorkflowRunStatus::PartiallyFailed => $run->markPartiallyFailed(),
        };

        self::assertSame($status, $run->status(), sprintf('Helper failed to reach status "%s".', $status->value));

        return $run;
    }
}
