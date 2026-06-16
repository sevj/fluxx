<?php

declare(strict_types=1);

namespace Fluxx\Tests\Command;

use DateTimeImmutable;
use Fluxx\Command\ListWorkflowRunsCommand;
use Fluxx\Operations\WorkflowRunFilterFactory;
use Fluxx\Operations\WorkflowRunListItem;
use Fluxx\Operations\WorkflowRunLister;
use Fluxx\Operations\WorkflowRunListing;
use Fluxx\Ui\WorkflowRunFilters;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ListWorkflowRunsCommandTest extends TestCase
{
    #[Test]
    public function it_renders_a_paginated_table(): void
    {
        $lister = $this->createMock(WorkflowRunLister::class);
        $lister->expects(self::once())
            ->method('list')
            ->with(
                self::callback(static fn (WorkflowRunFilters $filters): bool => $filters->workflowCode() === 'contacts'
                    && $filters->searchQuery() === 'run-1'),
                2,
                5,
            )
            ->willReturn(new WorkflowRunListing(
                items: [
                    new WorkflowRunListItem(
                        runId: 'run-1',
                        workflowCode: 'contacts',
                        trigger: 'manual',
                        status: 'failed',
                        sourceSystem: 'CSV',
                        targetSystem: 'Hubspot',
                        createdAt: new DateTimeImmutable('2026-06-16 10:00:00'),
                        errorMessage: 'boom',
                    ),
                ],
                currentPage: 2,
                perPage: 5,
                totalItems: 6,
                totalPages: 2,
            ));

        $tester = new CommandTester(new ListWorkflowRunsCommand($lister, new WorkflowRunFilterFactory()));
        $exitCode = $tester->execute([
            '--workflow' => 'contacts',
            '--search' => 'run-1',
            '--page' => '2',
            '--limit' => '5',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('run-1', $tester->getDisplay());
        self::assertStringContainsString('Showing page 2/2', $tester->getDisplay());
    }

    #[Test]
    public function it_rejects_invalid_pagination_values(): void
    {
        $tester = new CommandTester(new ListWorkflowRunsCommand(
            $this->createMock(WorkflowRunLister::class),
            new WorkflowRunFilterFactory(),
        ));

        $exitCode = $tester->execute([
            '--page' => '0',
        ]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('must be positive integers', $tester->getDisplay());
    }
}
