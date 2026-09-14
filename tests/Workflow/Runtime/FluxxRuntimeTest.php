<?php

declare(strict_types=1);

namespace Fluxx\Tests\Workflow\Runtime;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Fluxx\Entity\Enum\WorkflowRunStatus;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;
use Fluxx\Repository\WorkflowPayloadLookupInterface;
use Fluxx\Repository\WorkflowRunLookupInterface;
use Fluxx\Tests\Fixture\InMemoryStepRunLookup;
use Fluxx\Tests\Fixture\StubIdempotentStep;
use Fluxx\Workflow\Context\WorkflowContext;
use Fluxx\Workflow\Context\WorkflowContextFactory;
use Fluxx\Workflow\Error\WorkflowErrorCategory;
use Fluxx\Workflow\Error\WorkflowErrorInterface;
use Fluxx\Workflow\Error\WorkflowErrorPayloadFactory;
use Fluxx\Workflow\Lock\WorkflowExecutionLockManagerInterface;
use Fluxx\Workflow\Payload\WorkflowPayloadStoreInterface;
use Fluxx\Workflow\Retry\WorkflowRetryBackoffStrategy;
use Fluxx\Workflow\Retry\WorkflowRetryPolicy;
use Fluxx\Workflow\Runtime\FluxxRuntime;
use Fluxx\Workflow\Runtime\WorkflowCancellationSynchronizer;
use Fluxx\Workflow\Runtime\WorkflowIdempotenceResolver;
use Fluxx\Workflow\Runtime\WorkflowRetryScheduler;
use Fluxx\Workflow\Runtime\WorkflowRunCompletionDecider;
use Fluxx\Workflow\Result\WorkflowStepResult;
use Fluxx\Workflow\SynchronizationRegistry;
use Fluxx\Workflow\Step\ExecutableWorkflowStepInterface;
use Fluxx\Workflow\Step\WorkflowStepInput;
use Fluxx\Workflow\WorkflowDefinition;
use Fluxx\Workflow\WorkflowInterface;
use Fluxx\Workflow\WorkflowStepDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

final class FluxxRuntimeTest extends TestCase
{
    private WorkflowRunLookupInterface&MockObject $workflowRunRepository;
    private InMemoryStepRunLookup $stepRunLookup;

    /** @var list<string> */
    private array $transactionSequence = [];
    private WorkflowPayloadLookupInterface&MockObject $workflowPayloadRepository;
    private WorkflowPayloadStoreInterface&MockObject $workflowPayloadStore;
    private WorkflowExecutionLockManagerInterface&MockObject $workflowExecutionLockManager;
    private MessageBusInterface&MockObject $messageBus;
    private EntityManagerInterface&MockObject $entityManager;

    private FluxxRuntime $runtime;

    protected function setUp(): void
    {
        $this->workflowRunRepository = $this->createMock(WorkflowRunLookupInterface::class);
        $this->stepRunLookup = new InMemoryStepRunLookup();
        $this->workflowPayloadRepository = $this->createMock(WorkflowPayloadLookupInterface::class);
        $this->workflowPayloadStore = $this->createMock(WorkflowPayloadStoreInterface::class);
        $this->workflowExecutionLockManager = $this->createMock(WorkflowExecutionLockManagerInterface::class);
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $persistedStepRuns = [];
        $this->entityManager->method('persist')->willReturnCallback(function (object $entity) use (&$persistedStepRuns): void {
            if ($entity instanceof WorkflowStepRun) {
                $this->stepRunLookup->add($entity);
                $persistedStepRuns[] = $entity;
            }
        });
        $this->entityManager->method('flush')->willReturnCallback(static function (): void {});

        $txActive = false;
        $this->entityManager->method('beginTransaction')->willReturnCallback(function () use (&$txActive): void {
            $txActive = true;
            $this->transactionSequence[] = 'begin';
        });
        $this->entityManager->method('commit')->willReturnCallback(function () use (&$txActive): void {
            $txActive = false;
            $this->transactionSequence[] = 'commit';
        });
        $this->entityManager->method('rollback')->willReturnCallback(function () use (&$txActive): void {
            $txActive = false;
            $this->transactionSequence[] = 'rollback';
        });

        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturnCallback(static function () use (&$txActive): bool {
            return $txActive;
        });
        $this->entityManager->method('getConnection')->willReturn($connection);

        $this->workflowPayloadRepository->method('findByWorkflowRunAndTargetStepNameOrdered')->willReturn([]);
        $this->workflowPayloadRepository->method('findBySourceStepRunOrdered')->willReturn([]);

        $this->runtime = $this->buildRuntime($this->createRegistry());
    }

    private function buildRuntime(SynchronizationRegistry $registry): FluxxRuntime
    {
        $cancellationSynchronizer = new WorkflowCancellationSynchronizer($this->workflowRunRepository);
        $retryScheduler = new WorkflowRetryScheduler($this->messageBus);
        $idempotenceResolver = new WorkflowIdempotenceResolver(
            $this->stepRunLookup,
            $this->workflowPayloadRepository,
            $this->workflowPayloadStore,
        );
        $errorPayloadFactory = new WorkflowErrorPayloadFactory();
        $completionDecider = new WorkflowRunCompletionDecider();
        $contextFactory = new WorkflowContextFactory();

        return new FluxxRuntime(
            registry: $registry,
            entityManager: $this->entityManager,
            workflowRunRepository: $this->workflowRunRepository,
            workflowStepRunRepository: $this->stepRunLookup,
            workflowPayloadRepository: $this->workflowPayloadRepository,
            workflowPayloadStore: $this->workflowPayloadStore,
            workflowContextFactory: $contextFactory,
            workflowExecutionLockManager: $this->workflowExecutionLockManager,
            workflowErrorPayloadFactory: $errorPayloadFactory,
            workflowRunCompletionDecider: $completionDecider,
            cancellationSynchronizer: $cancellationSynchronizer,
            retryScheduler: $retryScheduler,
            idempotenceResolver: $idempotenceResolver,
        );
    }

    #[Test]
    public function it_returns_no_steps_when_run_is_already_terminal(): void
    {
        $workflowRun = $this->createWorkflowRun(WorkflowRunStatus::Completed);
        $this->workflowRunRepository->method('findOneByRunId')->willReturn($workflowRun);

        $result = $this->runtime->runStep('run-1', 'fetch');

        self::assertSame([], $result);
    }

    #[Test]
    public function it_executes_a_root_step_and_marks_it_completed(): void
    {
        $workflowRun = $this->createWorkflowRun(WorkflowRunStatus::Pending);
        $this->workflowRunRepository->method('findOneByRunId')->willReturn($workflowRun);

        $this->workflowExecutionLockManager->expects(self::once())->method('releaseForRun')->with($workflowRun, 'completed');

        $result = $this->runtime->runStep('run-1', 'fetch');

        self::assertSame(WorkflowRunStatus::Completed, $workflowRun->status());
        self::assertSame([], $result);
    }

    #[Test]
    public function it_deduplicates_a_step_via_idempotence_hit(): void
    {
        $workflowRun = $this->createWorkflowRun(WorkflowRunStatus::Pending);
        $this->workflowRunRepository->method('findOneByRunId')->willReturn($workflowRun);

        $this->registryWithHandler(new StubIdempotentStep('contact-1'), withIdempotence: true);

        $sourceRun = new WorkflowRun(
            runId: 'run-source',
            workflowName: 'fixture',
            sourceSystem: 'src',
            targetSystem: 'tgt',
            trigger: 'manual',
        );
        $sourceRun->markCompleted();
        $sourceStepRun = $this->createCompletedStepRun($sourceRun);
        $this->stepRunLookup->addCompleted($sourceStepRun, 'fixture', 'fetch', 'contact-1');

        $this->runtime->runStep('run-1', 'fetch');

        self::assertSame(WorkflowRunStatus::Completed, $workflowRun->status());
    }

    #[Test]
    public function it_schedules_a_retry_for_a_technical_error_and_dispatches_a_delayed_message(): void
    {
        $workflowRun = $this->createWorkflowRun(WorkflowRunStatus::Pending);
        $this->workflowRunRepository->method('findOneByRunId')->willReturn($workflowRun);

        $this->registryWithHandler(new FailingStepHandler(new TechnicalStepFailure('boom')), retryPolicy: new WorkflowRetryPolicy(
            maxRetries: 3,
            delaySeconds: 60,
            backoffStrategy: WorkflowRetryBackoffStrategy::Exponential,
        ));

        $dispatchedDelay = null;
        $this->messageBus
            ->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(function (object $message) use (&$dispatchedDelay): Envelope {
                if ($message instanceof Envelope) {
                    foreach ($message->all() as $stamps) {
                        foreach ($stamps as $stamp) {
                            if ($stamp instanceof DelayStamp) {
                                $dispatchedDelay = $stamp->getDelay();
                            }
                        }
                    }
                }

                return $message instanceof Envelope ? $message : new Envelope($message);
            });

        $result = $this->runtime->runStep('run-1', 'fetch');

        self::assertSame([], $result);
        self::assertSame(WorkflowRunStatus::Retrying, $workflowRun->status());
        self::assertSame(60_000, $dispatchedDelay);
    }

    #[Test]
    public function it_marks_a_business_error_as_failed_without_retry(): void
    {
        $workflowRun = $this->createWorkflowRun(WorkflowRunStatus::Pending);
        $this->workflowRunRepository->method('findOneByRunId')->willReturn($workflowRun);

        $this->registryWithHandler(new FailingStepHandler(new BusinessStepFailure('bad record')), retryPolicy: new WorkflowRetryPolicy(maxRetries: 3, delaySeconds: 60));

        $this->messageBus->expects(self::never())->method('dispatch');
        $this->workflowExecutionLockManager->expects(self::once())->method('releaseForRun')->with($workflowRun, 'failed');

        try {
            $this->runtime->runStep('run-1', 'fetch');
            self::fail('Expected the runtime to rethrow the business error.');
        } catch (BusinessStepFailure $exception) {
            self::assertSame('bad record', $exception->getMessage());
        }

        self::assertSame(WorkflowRunStatus::Failed, $workflowRun->status());
    }

    #[Test]
    public function it_synchronizes_a_run_cancelled_in_another_process_and_stops(): void
    {
        $workflowRun = $this->createWorkflowRun(WorkflowRunStatus::Running);
        $this->workflowRunRepository->method('findOneByRunId')->willReturn($workflowRun);
        $this->workflowRunRepository->method('findPersistedRunStateByRunId')->willReturn([
            'status' => WorkflowRunStatus::Cancelled->value,
            'metadata' => [],
            'finishedAt' => null,
        ]);

        $result = $this->runtime->runStep('run-1', 'fetch');

        self::assertSame([], $result);
        self::assertSame(WorkflowRunStatus::Cancelled, $workflowRun->status());
    }

    #[Test]
    public function it_wraps_successful_execution_in_a_single_transaction(): void
    {
        $workflowRun = $this->createWorkflowRun(WorkflowRunStatus::Pending);
        $this->workflowRunRepository->method('findOneByRunId')->willReturn($workflowRun);

        $this->runtime->runStep('run-1', 'fetch');

        self::assertSame(['begin', 'commit'], $this->transactionSequence);
    }

    #[Test]
    public function it_rolls_back_and_reopens_a_transaction_on_technical_error(): void
    {
        $workflowRun = $this->createWorkflowRun(WorkflowRunStatus::Pending);
        $this->workflowRunRepository->method('findOneByRunId')->willReturn($workflowRun);

        $this->registryWithHandler(
            new FailingStepHandler(new TechnicalStepFailure('boom')),
            retryPolicy: new WorkflowRetryPolicy(maxRetries: 3, delaySeconds: 60),
        );

        $this->messageBus
            ->method('dispatch')
            ->willReturnCallback(static function (object $message): Envelope {
                return $message instanceof Envelope ? $message : new Envelope($message);
            });

        $this->runtime->runStep('run-1', 'fetch');

        self::assertSame(['begin', 'rollback', 'begin', 'commit'], $this->transactionSequence);
        self::assertSame(WorkflowRunStatus::Retrying, $workflowRun->status());
    }

    private function createRegistry(): SynchronizationRegistry
    {
        $handler = new StubStepHandler(new WorkflowStepResult(
            records: [['id' => 1]],
            processedCount: 1,
            successCount: 1,
        ));
        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->method('definition')->willReturn($this->fixtureDefinition($handler));

        return new SynchronizationRegistry([$workflow]);
    }

    private function registryWithHandler(ExecutableWorkflowStepInterface $handler, ?WorkflowRetryPolicy $retryPolicy = null, bool $withIdempotence = false): void
    {
        $workflow = $this->createMock(WorkflowInterface::class);
        $workflow->method('definition')->willReturn($this->fixtureDefinition($handler, withIdempotence: $withIdempotence, retryPolicy: $retryPolicy));

        $this->runtime = $this->buildRuntime(new SynchronizationRegistry([$workflow]));
    }

    private function fixtureDefinition(
        ExecutableWorkflowStepInterface $handler,
        bool $withIdempotence = false,
        ?WorkflowRetryPolicy $retryPolicy = null,
    ): WorkflowDefinition {
        $step = new WorkflowStepDefinition(
            code: 'fetch',
            name: 'Fetch',
            type: 'read',
            handler: $handler,
            dependsOn: [],
            idempotence: $withIdempotence ? new \Fluxx\Workflow\Step\WorkflowStepIdempotence() : null,
            retryPolicy: $retryPolicy,
        );

        return new WorkflowDefinition(
            code: 'fixture',
            name: 'Fixture',
            sourceSystem: 'src',
            targetSystem: 'tgt',
            steps: [$step],
        );
    }

    private function createWorkflowRun(WorkflowRunStatus $status): WorkflowRun
    {
        $workflowRun = new WorkflowRun(
            runId: 'run-1',
            workflowName: 'fixture',
            sourceSystem: 'src',
            targetSystem: 'tgt',
            trigger: 'manual',
        );

        match ($status) {
            WorkflowRunStatus::Pending => null,
            WorkflowRunStatus::Running => $workflowRun->markRunning(),
            WorkflowRunStatus::Completed => $workflowRun->markCompleted(),
            WorkflowRunStatus::Failed => $workflowRun->markFailed('error'),
            WorkflowRunStatus::PartiallyFailed => $workflowRun->markPartiallyFailed('error'),
            WorkflowRunStatus::Cancelled => $workflowRun->markCancelled(),
            WorkflowRunStatus::Relaunched => $workflowRun->markRelaunched(),
            WorkflowRunStatus::Retrying => $workflowRun->markRetrying(),
        };

        return $workflowRun;
    }

    private function createCompletedStepRun(WorkflowRun $workflowRun): WorkflowStepRun
    {
        $stepRun = new WorkflowStepRun(
            workflowRun: $workflowRun,
            stepType: 'read',
            stepName: 'fetch',
            position: 1,
        );
        $stepRun->markCompleted(processedCount: 1, successCount: 1);

        return $stepRun;
    }
}

final class StubStepHandler implements ExecutableWorkflowStepInterface
{
    public function __construct(
        private readonly WorkflowStepResult $result,
    ) {
    }

    public function code(): string
    {
        return 'fetch';
    }

    public function name(): string
    {
        return 'Fetch';
    }

    public static function staticCode(): string
    {
        return 'fetch';
    }

    public function execute(WorkflowContext $context, WorkflowStepInput $input): WorkflowStepResult
    {
        return $this->result;
    }
}

final class FailingStepHandler implements ExecutableWorkflowStepInterface
{
    public function __construct(
        private readonly \Throwable $error,
    ) {
    }

    public function code(): string
    {
        return 'fetch';
    }

    public function name(): string
    {
        return 'Fetch';
    }

    public static function staticCode(): string
    {
        return 'fetch';
    }

    public function execute(WorkflowContext $context, WorkflowStepInput $input): WorkflowStepResult
    {
        throw $this->error;
    }
}

final class TechnicalStepFailure extends RuntimeException implements WorkflowErrorInterface
{
    public function workflowErrorCategory(): WorkflowErrorCategory
    {
        return WorkflowErrorCategory::Technical;
    }

    public function workflowErrorCode(): ?string
    {
        return 'TECHNICAL';
    }

    public function workflowErrorContext(): array
    {
        return [];
    }
}

final class BusinessStepFailure extends RuntimeException implements WorkflowErrorInterface
{
    public function workflowErrorCategory(): WorkflowErrorCategory
    {
        return WorkflowErrorCategory::Business;
    }

    public function workflowErrorCode(): ?string
    {
        return 'BUSINESS';
    }

    public function workflowErrorContext(): array
    {
        return [];
    }
}
