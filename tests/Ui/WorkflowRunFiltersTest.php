<?php

declare(strict_types=1);

namespace Fluxx\Tests\Ui;

use DateTimeImmutable;
use Fluxx\Ui\WorkflowRunFilters;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkflowRunFiltersTest extends TestCase
{
    #[Test]
    public function it_detects_active_filters_and_can_force_error_presence(): void
    {
        $filters = new WorkflowRunFilters(
            searchQuery: 'contacts',
            workflowCode: 'contacts',
            status: 'failed',
            sourceSystem: 'CSV',
            targetSystem: 'Hubspot',
            errorPresence: 'without',
            dateFrom: new DateTimeImmutable('2026-06-01 00:00:00'),
            dateTo: new DateTimeImmutable('2026-06-30 23:59:59'),
        );

        self::assertTrue($filters->hasActiveFilters());
        self::assertSame('without', $filters->errorPresence());

        $forced = $filters->withErrorPresence('with');

        self::assertSame('with', $forced->errorPresence());
        self::assertSame('contacts', $forced->searchQuery());
        self::assertSame('contacts', $forced->workflowCode());
    }
}
