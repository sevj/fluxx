<?php

declare(strict_types=1);

namespace Fluxx\Tests\Workflow;

use Doctrine\ORM\EntityManagerInterface;
use Fluxx\Entity\Enum\WorkflowRunStatus;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Tests\Fixture\FixtureWorkflow;
use Fluxx\Tests\Fixture\StubExecutableStep;
use Fluxx\Workflow\Error\WorkflowErrorPayloadFactory;
use Fluxx\Workflow\FluxxEngine;
use Fluxx\Workflow\Lock\WorkflowExecutionLockManagerInterface;
use Fluxx\Workflow\Message\RunWorkflowStepMessage;
use Fluxx\Workflow\SynchronizationRegistry;
use Fluxx\Workflow\WorkflowDefinition;
use Fluxx\Workflow\WorkflowStepDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class FluxxEngineTest extends TestCase
{
    #[Test]
    public function it_persists_a_run_and_dispatches_all_root_steps(): void
    {
        $persistedRun = null;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::callback(static function ($entity) use (&$persistedRun): bool {
                $persistedRun = $entity;

                return $entity instanceof WorkflowRun;
            }));
        $entityManager->expects(self::once())->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::exactly(2))
            ->method('dispatch')
            ->with(self::callback(static fn ($message): bool => $message instanceof RunWorkflowStepMessage))
            ->willReturn(new Envelope(new RunWorkflowStepMessage('run-test', 'read_contacts')));

        $lockManager = $this->createMock(WorkflowExecutionLockManagerInterface::class);
        $lockManager->expects(self::once())->method('acquire');
        $lockManager->expects(self::never())->method('releaseForRun');

        $engine = new FluxxEngine(
            registry: $this->registryWithRoots(),
            entityManager: $entityManager,
            messageBus: $messageBus,
            workflowExecutionLockManager: $lockManager,
            workflowErrorPayloadFactory: new WorkflowErrorPayloadFactory(),
        );

        $runId = $engine->run('contacts');

        self::assertIsString($runId);
        self::assertInstanceOf(WorkflowRun::class, $persistedRun);
        self::assertSame(WorkflowRunStatus::Running, $persistedRun->status());
        self::assertSame('contacts', $persistedRun->workflowName());
    }

    #[Test]
    public function it_marks_the_run_failed_and_releases_the_lock_when_dispatch_fails(): void
    {
        $persistedRun = null;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::callback(static function ($entity) use (&$persistedRun): bool {
                $persistedRun = $entity;

                return $entity instanceof WorkflowRun;
            }));
        $entityManager->expects(self::exactly(2))->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())
            ->method('dispatch')
            ->willThrowException(new RuntimeException('dispatch failed'));

        $lockManager = $this->createMock(WorkflowExecutionLockManagerInterface::class);
        $lockManager->expects(self::once())->method('acquire');
        $lockManager->expects(self::once())
            ->method('releaseForRun')
            ->with(
                self::callback(static fn (WorkflowRun $run): bool => $run->status() === WorkflowRunStatus::Failed),
                'dispatch_failed',
            );

        $engine = new FluxxEngine(
            registry: $this->registryWithRoots(),
            entityManager: $entityManager,
            messageBus: $messageBus,
            workflowExecutionLockManager: $lockManager,
            workflowErrorPayloadFactory: new WorkflowErrorPayloadFactory(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dispatch failed');

        try {
            $engine->run('contacts');
        } finally {
            self::assertInstanceOf(WorkflowRun::class, $persistedRun);
            self::assertSame(WorkflowRunStatus::Failed, $persistedRun->status());
            self::assertSame('dispatch failed', $persistedRun->errorMessage());
        }
    }

    private function registryWithRoots(): SynchronizationRegistry
    {
        $rootOne = new StubExecutableStep('read_contacts', 'Read contacts');
        $rootTwo = new StubExecutableStep('read_companies', 'Read companies');
        $join = new StubExecutableStep('join_records', 'Join records');

        return new SynchronizationRegistry([
            new FixtureWorkflow(new WorkflowDefinition(
                code: 'contacts',
                name: 'Contacts',
                sourceSystem: 'CSV',
                targetSystem: 'Hubspot',
                steps: [
                    new WorkflowStepDefinition('read_contacts', 'Read contacts', 'read', $rootOne),
                    new WorkflowStepDefinition('read_companies', 'Read companies', 'read', $rootTwo),
                    new WorkflowStepDefinition('join_records', 'Join records', 'transform', $join, ['read_contacts', 'read_companies']),
                ],
            )),
        ]);
    }
}
