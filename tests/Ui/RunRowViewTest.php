<?php

declare(strict_types=1);

namespace Fluxx\Tests\Ui;

use DateTimeImmutable;
use Fluxx\Ui\RunRowView;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RunRowViewTest extends TestCase
{
    #[Test]
    public function it_computes_the_duration_of_a_finished_run(): void
    {
        $row = $this->row(new DateTimeImmutable('2026-11-04 20:40:00'), new DateTimeImmutable('2026-11-04 20:41:30'));

        self::assertSame(90000, $row->durationMs());
    }

    #[Test]
    public function it_has_no_duration_until_the_run_is_finished(): void
    {
        self::assertNull($this->row(null, null)->durationMs());
        self::assertNull($this->row(new DateTimeImmutable('2026-11-04 20:40:00'), null)->durationMs());
    }

    private function row(?DateTimeImmutable $startedAt, ?DateTimeImmutable $finishedAt): RunRowView
    {
        return new RunRowView(
            runId: 'run',
            workflowCode: 'contacts',
            workflowName: 'Contacts',
            workflowCategory: null,
            sourceSystem: 'CSV',
            targetSystem: 'Hubspot',
            trigger: 'console',
            status: 'completed',
            synchronous: false,
            lockKey: null,
            lockScope: null,
            createdAt: new DateTimeImmutable('2026-11-04 20:39:00'),
            startedAt: $startedAt,
            finishedAt: $finishedAt,
            errorMessage: null,
        );
    }
}
