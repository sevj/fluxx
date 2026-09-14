<?php

declare(strict_types=1);

namespace Fluxx\Tests\Workflow\Relaunch;

use Doctrine\ORM\EntityManagerInterface;
use Fluxx\Entity\Enum\WorkflowRunStatus;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Repository\WorkflowPayloadLookupInterface;
use Fluxx\Repository\WorkflowRunLookupInterface;
use Fluxx\Repository\WorkflowStepRunLookupInterface;
use Fluxx\Workflow\Lock\WorkflowExecutionLockManagerInterface;
use Fluxx\Workflow\Payload\WorkflowPayloadStoreInterface;
use Fluxx\Workflow\Relaunch\RunStillActiveException;
use Fluxx\Workflow\Relaunch\WorkflowRelaunchPlanner;
use Fluxx\Workflow\Relaunch\WorkflowRelaunchService;
use Fluxx\Workflow\SynchronizationRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Messenger\MessageBusInterface;

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
