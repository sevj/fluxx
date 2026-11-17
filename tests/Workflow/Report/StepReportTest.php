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
    public function links_round_trip_with_their_removed_count(): void
    {
        $report = StepReport::links('Contacts ↔ Companies')->read(12)->created(10)->removed(2)->excluded('Unknown company', 1);

        $array = $report->toArray();

        self::assertTrue($array['objects']['link']);
        self::assertSame(2, $array['objects']['removed']);
        self::assertEquals($report, StepReport::fromArray($array));
    }

    #[Test]
    public function a_step_report_stored_before_links_reads_as_objects(): void
    {
        $array = StepReport::objects('Contacts')->created(1)->toArray();
        unset($array['objects']['link'], $array['objects']['removed']);

        $stats = StepReport::fromArray($array)->objectStats();

        self::assertFalse($stats->link);
        self::assertSame(0, $stats->removed);
    }

    #[Test]
    public function only_links_can_be_removed(): void
    {
        $this->expectException(\LogicException::class);

        StepReport::objects('Contacts')->removed(1);
    }

    #[Test]
    public function links_cannot_be_updated(): void
    {
        $this->expectException(\LogicException::class);

        StepReport::links('Contacts ↔ Companies')->updated(1);
    }

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
    public function an_unknown_version_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        StepReport::fromArray(['v' => 2, 'objects' => ['label' => 'Contacts']]);
    }

    #[Test]
    public function missing_objects_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        StepReport::fromArray(['v' => 1]);
    }

    #[Test]
    public function an_unknown_severity_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        StepReport::fromArray(['v' => 1, 'objects' => ['label' => 'Contacts'], 'anomalies' => [['title' => 'X', 'severity' => 'fatal', 'lines' => []]]]);
    }

    #[Test]
    public function an_anomaly_is_a_warning_by_default(): void
    {
        $array = StepReport::objects('Contacts')->anomaly('Orphans', ['a' => 1])->toArray();

        self::assertSame('warning', $array['anomalies'][0]['severity']);
    }
}
