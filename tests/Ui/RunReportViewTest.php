<?php

declare(strict_types=1);

namespace Fluxx\Tests\Ui;

use DateTimeImmutable;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowRunReport;
use Fluxx\Tests\Fixture\RunReportContent;
use Fluxx\Ui\RunReportView;
use Fluxx\Ui\RunTimelineStepView;
use Fluxx\Ui\RunTimelineView;
use Fluxx\Ui\UnreadableRunReportException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RunReportViewTest extends TestCase
{
    #[Test]
    public function view_formats_summary_objects_and_anomalies(): void
    {
        $view = RunReportView::fromStored($this->report($this->content()), $this->timeline([]));

        self::assertSame('completed_with_warnings', $view->status);
        self::assertSame('Efficy sync', $view->workflowName);
        self::assertSame(
            ['1 min 41 s', '7 012', '6 900', '1', '2'],
            array_column($view->kpis, 'value'),
        );
        self::assertSame('run_report.kpi.links_hint', $view->kpis[2]['hint']);
        self::assertSame(['%count%' => '1', '%removed%' => '12'], $view->kpis[2]['hintParameters']);
        self::assertSame([
            'label' => 'Contacts ↔ Companies',
            'source' => 'Write',
            'read' => '6 912',
            'excluded' => '0',
            'written' => '6 900',
            'removed' => '12',
            'rejected' => '0',
            'duration' => '1 min 30 s',
            'rate' => '77',
        ], $view->links[0]);
        self::assertSame('error', $view->kpis[3]['tone']);
        self::assertSame(['%reasons%' => 'No email'], $view->kpis[4]['hintParameters']);

        self::assertSame([
            'label' => 'Contacts',
            'read' => '10 000',
            'excluded' => '2',
            'written' => '7 012',
            'created' => '4',
            'rejected' => '1',
            'duration' => '1 min 30 s',
            'rate' => '78',
        ], $view->objects[0]);

        self::assertSame([['name' => 'Read', 'duration' => '10.0 s'], ['name' => 'Write', 'duration' => '1 min 30 s']], $view->criticalPath);
        self::assertSame(99, $view->criticalPathShare);
        self::assertSame('Write', $view->longestStep['name'] ?? null);
        self::assertSame('2.00 MB', $view->memoryPeak['value'] ?? null);

        [$declared, $excluded, $rejected] = $view->anomalies;
        self::assertSame('Unresolved owners', $declared['title']);
        self::assertSame('write · Contacts', $declared['source']);
        self::assertSame('completed-with-warnings', $declared['tone']);
        self::assertSame([['key' => 'jdoe', 'value' => '3 005'], ['key' => '<b>x</b>', 'value' => 'unknown']], $declared['lines']);
        self::assertSame(23, $declared['hiddenLines']);
        self::assertSame('run_report.anomalies.excluded_title', $excluded['titleKey']);
        self::assertSame([['key' => 'Contacts · No email', 'value' => '2']], $excluded['lines']);
        self::assertSame([['key' => 'c-1', 'value' => 'Invalid email']], $rejected['lines']);

        self::assertSame('failed', $view->checks[0]['tone']);
        self::assertSame(['c-9'], $view->checks[0]['samples']);
        self::assertSame('completed', $view->sections[0]['lines'][0]['tone']);
        self::assertFalse($view->sections[1]['available']);
        self::assertSame([['step' => 'verify_links', 'message' => 'Unknown report kind "chart".']], $view->unreadableStepReports);
    }

    #[Test]
    public function absent_data_is_shown_as_a_dash(): void
    {
        $content = $this->content();
        $content['objects'] = [];
        $content['links'] = [];
        $content['critical_path'] = [];
        unset($content['summary']['duration_ms'], $content['summary']['critical_path_ms'], $content['summary']['longest_step'], $content['summary']['memory_peak_step']);

        $view = RunReportView::fromStored($this->report($content), $this->timeline([]));

        self::assertSame(['—', '—', '—', '—', '—'], array_column($view->kpis, 'value'));
        self::assertSame('run_report.kpi.links_absent_hint', $view->kpis[2]['hint']);
        self::assertSame([], $view->links);
        self::assertSame([], $view->criticalPath);
        self::assertSame('—', $view->criticalPathDuration);
        self::assertNull($view->criticalPathShare);
        self::assertNull($view->longestStep);
        self::assertNull($view->memoryPeak);
    }

    #[Test]
    public function malformed_parts_of_a_readable_report_are_left_out(): void
    {
        $view = RunReportView::fromStored($this->report([
            'format_version' => 1,
            'summary' => ['status' => 'completed', 'objects' => 'oops', 'duration_ms' => '12', 'longest_step' => ['x']],
            'objects' => [['label' => 'Contacts', 'excluded' => 'oops', 'steps' => 'oops'], 'oops'],
            'steps' => 'oops',
            'critical_path' => [['read']],
            'anomalies' => [['title' => 'Owners', 'lines' => 'oops', 'line_total' => 'many']],
            'checks' => [['name' => 'Count', 'samples' => [['nested']]]],
            'sections' => [['lines' => ['oops', ['label' => ['x']]]]],
        ]), $this->timeline([]));

        self::assertSame('—', $view->kpis[0]['value']);
        self::assertSame('—', $view->objects[0]['read']);
        self::assertSame([], $view->criticalPath);
        self::assertNull($view->longestStep);
        self::assertSame([], $view->anomalies[0]['lines']);
        self::assertSame([], $view->checks[0]['samples']);
        self::assertSame('—', $view->sections[0]['lines'][0]['label']);
    }

    #[Test]
    public function short_steps_are_grouped(): void
    {
        $content = $this->content();
        $content['steps'][] = ['code' => 'old', 'name' => 'Old', 'duration_ms' => 120000, 'relaunch' => 'preserved', 'throughput_per_second' => null];
        $content['steps'][] = ['code' => 'tiny', 'name' => 'Tiny', 'duration_ms' => 400, 'relaunch' => 'preserved'];

        $view = RunReportView::fromStored($this->report($content), $this->timeline([
            $this->timelineStep('read', 10000),
            $this->timelineStep('write', 90000),
            $this->timelineStep('verify', 59999),
            $this->timelineStep('old', 120000),
            $this->timelineStep('tiny', 400),
            $this->timelineStep('never', null, pending: true),
        ]));

        self::assertSame(['write', 'old'], array_map(static fn (array $row): string => $row['step']->code(), $view->timelineRows));
        self::assertTrue($view->timelineRows[0]['critical']);
        self::assertFalse($view->timelineRows[0]['preserved']);
        self::assertSame('1 min 30 s', $view->timelineRows[0]['duration']);
        self::assertSame('111', $view->timelineRows[0]['rate']);
        self::assertTrue($view->timelineRows[1]['preserved']);
        self::assertFalse($view->timelineRows[1]['critical']);
        self::assertNull($view->timelineRows[1]['rate']);
        self::assertSame(3, $view->shortStepCount);
        self::assertSame(1, $view->shortPreservedCount);
    }

    #[Test]
    public function a_report_of_another_format_version_is_unreadable(): void
    {
        $this->expectException(UnreadableRunReportException::class);

        RunReportView::fromStored(
            new WorkflowRunReport(new WorkflowRun('run-1', 'sync', 'src', 'tgt', 'manual'), 2, ['format_version' => 2] + $this->content()),
            $this->timeline([]),
        );
    }

    #[Test]
    public function a_report_without_summary_is_unreadable(): void
    {
        $this->expectException(UnreadableRunReportException::class);

        RunReportView::fromStored($this->report(['format_version' => 1, 'summary' => 'broken']), $this->timeline([]));
    }

    private function report(array $content): WorkflowRunReport
    {
        return new WorkflowRunReport(new WorkflowRun('run-1', 'efficy_sync', 'src', 'tgt', 'manual'), 1, $content, new DateTimeImmutable('2026-11-17 09:00:00'));
    }

    /**
     * @param list<RunTimelineStepView> $steps
     */
    private function timeline(array $steps): RunTimelineView
    {
        return new RunTimelineView(new DateTimeImmutable('2026-11-17 08:00:00'), new DateTimeImmutable('2026-11-17 08:01:41'), 101000, false, $steps !== [], $steps);
    }

    private function timelineStep(string $code, ?int $durationMs, bool $pending = false): RunTimelineStepView
    {
        return new RunTimelineStepView(
            code: $code,
            name: ucfirst($code),
            status: 'completed',
            typeTone: 'neutral',
            typeToneStyle: null,
            position: 1,
            startedAt: $pending ? null : new DateTimeImmutable('2026-11-17 08:00:00'),
            finishedAt: null,
            durationMs: $durationMs,
            leftPercent: $pending ? null : 0.0,
            widthPercent: $pending ? null : 50.0,
            isRunning: false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function content(): array
    {
        return RunReportContent::sample();
    }
}
