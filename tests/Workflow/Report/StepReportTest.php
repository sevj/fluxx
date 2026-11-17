<?php

declare(strict_types=1);

namespace Fluxx\Tests\Workflow\Report;

use Fluxx\Workflow\Report\Severity;
use Fluxx\Workflow\Report\StepReport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StepReportTest extends TestCase
{
    #[Test]
    public function step_report_round_trips(): void
    {
        $report = StepReport::objects('Contacts')
            ->read(10)
            ->created(4)
            ->updated(3)
            ->excluded('SIFUREP', 2)
            ->rejected([['key' => 'c-1', 'message' => 'Invalid email']])
            ->anomaly('Duplicates', ['c-2' => 'twice', 'c-3' => 3], Severity::Blocking);

        $array = $report->toArray();

        self::assertSame(1, $array['v']);
        self::assertEquals($report, StepReport::fromArray($array));
        self::assertSame($array, StepReport::fromArray($array)->toArray());
    }

    #[Test]
    public function excluded_reasons_add_up(): void
    {
        $report = StepReport::objects('Contacts')->excluded('SIFUREP', 3)->excluded('SIFUREP', 3);

        self::assertSame(['SIFUREP' => 6], $report->toArray()['objects']['excluded']);
    }

    #[Test]
    public function with_methods_do_not_mutate_the_original(): void
    {
        $base = StepReport::objects('Contacts');
        $base->read(5);

        self::assertSame(0, $base->toArray()['objects']['read']);
    }

    #[Test]
    public function an_anomaly_is_a_warning_by_default(): void
    {
        $array = StepReport::objects('Contacts')->anomaly('Orphans', ['a' => 1])->toArray();

        self::assertSame('warning', $array['anomalies'][0]['severity']);
    }
}
