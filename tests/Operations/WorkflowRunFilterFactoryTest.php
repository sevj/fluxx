<?php

declare(strict_types=1);

namespace Fluxx\Tests\Operations;

use Fluxx\Operations\WorkflowRunFilterFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkflowRunFilterFactoryTest extends TestCase
{
    #[Test]
    public function it_builds_normalized_filters_from_input_values(): void
    {
        $filters = (new WorkflowRunFilterFactory())->fromArray([
            'q' => ' contacts ',
            'status' => 'failed',
            'source' => 'CSV',
            'target' => 'Hubspot',
            'errors' => 'with',
            'from' => '2026-06-01',
            'to' => '2026-06-30',
        ], 'contacts');

        self::assertSame('contacts', $filters->searchQuery());
        self::assertSame('contacts', $filters->workflowCode());
        self::assertSame('failed', $filters->status());
        self::assertSame('CSV', $filters->sourceSystem());
        self::assertSame('Hubspot', $filters->targetSystem());
        self::assertSame('with', $filters->errorPresence());
        self::assertSame('2026-06-01 00:00:00', $filters->dateFrom()?->format('Y-m-d H:i:s'));
        self::assertSame('2026-06-30 23:59:59', $filters->dateTo()?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function it_falls_back_cleanly_for_invalid_optional_values(): void
    {
        $filters = (new WorkflowRunFilterFactory())->fromArray([
            'workflow' => '  ',
            'errors' => 'unexpected',
            'from' => 'invalid',
            'to' => '2026-13-99',
        ]);

        self::assertNull($filters->workflowCode());
        self::assertSame('all', $filters->errorPresence());
        self::assertNull($filters->dateFrom());
        self::assertNull($filters->dateTo());
    }
}
