<?php

declare(strict_types=1);

namespace Fluxx\Ui;

use DateTimeImmutable;
use Fluxx\Entity\WorkflowRunReport;
use Fluxx\Workflow\Report\RunReportBuilder;

/**
 * The stored report of a run, formatted for the run page. Numbers and durations come out as display
 * strings and absent data as "—"; translation keys are left to the template.
 */
final readonly class RunReportView
{
    public const ABSENT = '—';
    public const SHORT_STEP_MS = 60000;

    /**
     * @param list<array{label: string, value: string, hint: ?string, hintParameters: array<string, string>, tone: ?string}> $kpis
     * @param list<array{label: string, read: string, excluded: string, written: string, created: string, rejected: string, duration: string, rate: ?string}> $objects
     * @param list<array{label: string, source: string, read: string, excluded: string, written: string, removed: string, rejected: string, duration: string, rate: ?string}> $links
     * @param list<array{step: RunTimelineStepView, critical: bool, preserved: bool, duration: string, rate: ?string}> $timelineRows
     * @param list<array{name: string, duration: string}> $criticalPath
     * @param array{code: string, name: string, duration: string, processed: string, rate: ?string}|null $longestStep
     * @param array{code: string, name: string, value: string}|null $memoryPeak
     * @param list<array{title: ?string, titleKey: ?string, source: string, tone: string, kindKey: string, countKey: string, count: string, lines: list<array{key: string, value: string}>, hiddenLines: int}> $anomalies
     * @param list<array{step: string, message: string}> $unreadableStepReports
     * @param list<array{step: string, name: string, severityKey: string, outcomeKey: string, tone: string, expected: string, actual: string, samples: list<string>}> $checks
     * @param list<array{title: string, available: bool, message: ?string, lines: list<array{label: string, detail: ?string, status: ?string, tone: string}>}> $sections
     */
    public function __construct(
        public string $workflowCode,
        public string $workflowName,
        public string $status,
        public string $trigger,
        public ?DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $finishedAt,
        public DateTimeImmutable $builtAt,
        public array $kpis,
        public array $objects,
        public array $links,
        public bool $hasTiming,
        public array $timelineRows,
        public int $shortStepCount,
        public int $shortPreservedCount,
        public array $criticalPath,
        public string $criticalPathDuration,
        public ?int $criticalPathShare,
        public ?array $longestStep,
        public ?array $memoryPeak,
        public array $anomalies,
        public array $unreadableStepReports,
        public array $checks,
        public array $sections,
    ) {
    }

    /**
     * @throws UnreadableRunReportException when the report has another format version or no readable summary
     */
    public static function fromStored(WorkflowRunReport $report, RunTimelineView $timeline): self
    {
        $content = $report->content();

        if ($report->formatVersion() !== RunReportBuilder::FORMAT_VERSION || ($content['format_version'] ?? null) !== RunReportBuilder::FORMAT_VERSION) {
            throw new UnreadableRunReportException(sprintf('The report has the format version %d, %d is expected.', $report->formatVersion(), RunReportBuilder::FORMAT_VERSION));
        }

        $summary = $content['summary'] ?? null;

        if (!is_array($summary) || !is_string($summary['status'] ?? null)) {
            throw new UnreadableRunReportException('The report has no readable summary.');
        }

        $steps = [];
        foreach (self::rows($content, 'steps') as $step) {
            if (is_string($step['code'] ?? null)) {
                $steps[$step['code']] = $step;
            }
        }

        $objects = self::rows($content, 'objects');
        $links = self::rows($content, 'links');
        [$timelineRows, $shortStepCount, $shortPreservedCount] = self::timelineRows($timeline, $steps, self::stringList($content['critical_path'] ?? null));

        return new self(
            workflowCode: self::string($summary, 'workflow_code') ?? '',
            workflowName: self::string($summary, 'workflow_name') ?? self::string($summary, 'workflow_code') ?? '',
            status: $summary['status'],
            trigger: self::string($summary, 'trigger') ?? self::ABSENT,
            startedAt: self::date($summary, 'started_at'),
            finishedAt: self::date($summary, 'finished_at'),
            builtAt: $report->builtAt(),
            kpis: self::kpis($summary, $objects, $links),
            objects: array_map(static fn (array $object): array => self::objectRow($object, $steps), $objects),
            links: array_map(static fn (array $link): array => self::linkRow($link, $steps), $links),
            hasTiming: $timeline->hasTiming(),
            timelineRows: $timelineRows,
            shortStepCount: $shortStepCount,
            shortPreservedCount: $shortPreservedCount,
            criticalPath: array_map(
                static fn (string $code): array => [
                    'name' => self::string($steps[$code] ?? [], 'name') ?? $code,
                    'duration' => self::duration(self::int($steps[$code] ?? [], 'duration_ms')),
                ],
                self::stringList($content['critical_path'] ?? null),
            ),
            criticalPathDuration: self::duration(self::int($summary, 'critical_path_ms')),
            criticalPathShare: self::share(self::int($summary, 'critical_path_ms'), self::int($summary, 'duration_ms')),
            longestStep: self::longestStep($steps[self::string($summary, 'longest_step') ?? ''] ?? null),
            memoryPeak: self::memoryPeak($steps[self::string($summary, 'memory_peak_step') ?? ''] ?? null),
            anomalies: [...self::declaredAnomalies($content), ...self::exclusions([...$objects, ...$links]), ...self::rejections([...$objects, ...$links])],
            unreadableStepReports: array_values(array_map(
                static fn (array $row): array => ['step' => self::string($row, 'step') ?? self::ABSENT, 'message' => self::string($row, 'message') ?? ''],
                self::rows($content, 'unreadable_step_reports'),
            )),
            checks: array_map(self::checkRow(...), self::rows($content, 'checks')),
            sections: array_map(self::sectionRow(...), self::rows($content, 'sections')),
        );
    }

    /**
     * @param array<string, mixed> $summary
     * @param list<array<string, mixed>> $objects
     * @param list<array<string, mixed>> $links
     * @return list<array{label: string, value: string, hint: ?string, hintParameters: array<string, string>, tone: ?string}>
     */
    private static function kpis(array $summary, array $objects, array $links): array
    {
        $linkTotals = is_array($summary['links'] ?? null) && $links !== [] ? $summary['links'] : [];
        $totals = is_array($summary['objects'] ?? null) && $objects !== [] ? $summary['objects'] : [];
        $rejected = self::int($totals, 'rejected');
        $reasons = [];

        foreach ($objects as $object) {
            foreach (is_array($object['excluded'] ?? null) ? $object['excluded'] : [] as $reason => $count) {
                if (is_int($count) && $count > 0) {
                    $reasons[(string) $reason] = ($reasons[(string) $reason] ?? 0) + $count;
                }
            }
        }
        arsort($reasons);

        return [
            [
                'label' => 'run_report.kpi.duration',
                'value' => self::duration(self::int($summary, 'duration_ms')),
                'hint' => 'run_report.kpi.duration_hint',
                'hintParameters' => [
                    '%steps%' => self::number(self::int($summary, 'step_count')),
                    '%warnings%' => self::number(self::int($summary, 'warning_count')),
                ],
                'tone' => null,
            ],
            [
                'label' => 'run_report.kpi.written',
                'value' => self::number(self::int($totals, 'written')),
                'hint' => $objects === [] ? null : 'run_report.kpi.written_hint',
                'hintParameters' => ['%count%' => self::number(count($objects))],
                'tone' => null,
            ],
            [
                'label' => 'run_report.kpi.links',
                'value' => self::number(self::int($linkTotals, 'written')),
                'hint' => $links === [] ? 'run_report.kpi.links_absent_hint' : 'run_report.kpi.links_hint',
                'hintParameters' => $links === [] ? [] : [
                    '%count%' => self::number(count($links)),
                    '%removed%' => self::number(self::int($linkTotals, 'removed')),
                ],
                'tone' => null,
            ],
            [
                'label' => 'run_report.kpi.rejected',
                'value' => self::number($rejected),
                'hint' => $objects === [] ? null : 'run_report.kpi.rejected_hint',
                'hintParameters' => [],
                'tone' => $rejected !== null && $rejected > 0 ? 'error' : null,
            ],
            [
                'label' => 'run_report.kpi.excluded',
                'value' => self::number(self::int($totals, 'excluded')),
                'hint' => $reasons === [] ? null : 'run_report.kpi.excluded_hint',
                'hintParameters' => ['%reasons%' => implode(', ', array_slice(array_keys($reasons), 0, 3))],
                'tone' => null,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $object
     * @param array<string, array<string, mixed>> $steps
     * @return array{label: string, read: string, excluded: string, written: string, created: string, rejected: string, duration: string, rate: ?string}
     */
    private static function objectRow(array $object, array $steps): array
    {
        $durationMs = null;

        foreach (self::stringList($object['steps'] ?? null) as $code) {
            $stepDuration = self::int($steps[$code] ?? [], 'duration_ms');

            if ($stepDuration !== null) {
                $durationMs = ($durationMs ?? 0) + $stepDuration;
            }
        }

        $written = self::int($object, 'written');

        return [
            'label' => self::string($object, 'label') ?? self::ABSENT,
            'read' => self::number(self::int($object, 'read')),
            'excluded' => self::number(self::int($object, 'excluded_total')),
            'written' => self::number($written),
            'created' => self::number(self::int($object, 'created')),
            'rejected' => self::number(self::int($object, 'rejected_total')),
            'duration' => self::duration($durationMs),
            'rate' => $written !== null && $written > 0 && $durationMs !== null && $durationMs > 0 ? self::rate($written / ($durationMs / 1000)) : null,
        ];
    }

    /**
     * @param array<string, mixed> $link
     * @param array<string, array<string, mixed>> $steps
     * @return array{label: string, source: string, read: string, excluded: string, written: string, removed: string, rejected: string, duration: string, rate: ?string}
     */
    private static function linkRow(array $link, array $steps): array
    {
        $row = self::objectRow($link, $steps);

        return [
            'label' => $row['label'],
            'source' => implode(', ', array_map(
                static fn (string $code): string => self::string($steps[$code] ?? [], 'name') ?? $code,
                self::stringList($link['steps'] ?? null),
            )),
            'read' => $row['read'],
            'excluded' => $row['excluded'],
            'written' => $row['written'],
            'removed' => self::number(self::int($link, 'removed')),
            'rejected' => $row['rejected'],
            'duration' => $row['duration'],
            'rate' => $row['rate'],
        ];
    }

    /**
     * Steps shorter than a minute are counted instead of drawn, so the long ones stay readable.
     *
     * @param array<string, array<string, mixed>> $steps
     * @param list<string> $criticalPath
     * @return array{list<array{step: RunTimelineStepView, critical: bool, preserved: bool, duration: string, rate: ?string}>, int, int}
     */
    private static function timelineRows(RunTimelineView $timeline, array $steps, array $criticalPath): array
    {
        $latest = [];
        foreach ($timeline->steps() as $step) {
            if (!$step->isPending()) {
                unset($latest[$step->code()]);
                $latest[$step->code()] = $step;
            }
        }

        $rows = [];
        $short = 0;
        $shortPreserved = 0;

        foreach ($latest as $code => $step) {
            $reported = $steps[$code] ?? [];
            $durationMs = self::int($reported, 'duration_ms') ?? $step->durationMs();
            $preserved = ($reported['relaunch'] ?? null) === 'preserved';

            if ($durationMs === null || $durationMs < self::SHORT_STEP_MS) {
                ++$short;
                $shortPreserved += $preserved ? 1 : 0;

                continue;
            }

            $throughput = $reported['throughput_per_second'] ?? null;
            $rows[] = [
                'step' => $step,
                'critical' => in_array($code, $criticalPath, true),
                'preserved' => $preserved,
                'duration' => self::duration($durationMs),
                'rate' => is_int($throughput) || is_float($throughput) ? self::rate((float) $throughput) : null,
            ];
        }

        return [$rows, $short, $shortPreserved];
    }

    /**
     * @param array<string, mixed>|null $step
     * @return array{code: string, name: string, duration: string, processed: string, rate: ?string}|null
     */
    private static function longestStep(?array $step): ?array
    {
        if ($step === null) {
            return null;
        }

        $throughput = $step['throughput_per_second'] ?? null;

        return [
            'code' => (string) $step['code'],
            'name' => self::string($step, 'name') ?? (string) $step['code'],
            'duration' => self::duration(self::int($step, 'duration_ms')),
            'processed' => self::number(self::int($step, 'processed_count')),
            'rate' => is_int($throughput) || is_float($throughput) ? self::rate((float) $throughput) : null,
        ];
    }

    /**
     * @param array<string, mixed>|null $step
     * @return array{code: string, name: string, value: string}|null
     */
    private static function memoryPeak(?array $step): ?array
    {
        $bytes = $step !== null ? self::int($step, 'memory_peak_bytes') : null;

        if ($step === null || $bytes === null) {
            return null;
        }

        return [
            'code' => (string) $step['code'],
            'name' => self::string($step, 'name') ?? (string) $step['code'],
            'value' => number_format($bytes / 1048576, 2, '.', ' ') . ' MB',
        ];
    }

    /**
     * @param array<string, mixed> $content
     * @return list<array{title: ?string, titleKey: ?string, source: string, tone: string, kindKey: string, countKey: string, count: string, lines: list<array{key: string, value: string}>, hiddenLines: int}>
     */
    private static function declaredAnomalies(array $content): array
    {
        $anomalies = [];

        foreach (self::rows($content, 'anomalies') as $anomaly) {
            $blocking = ($anomaly['severity'] ?? null) === 'blocking';
            $lines = [];

            foreach (is_array($anomaly['lines'] ?? null) ? $anomaly['lines'] : [] as $key => $value) {
                if (is_int($value) || is_string($value)) {
                    $lines[] = ['key' => (string) $key, 'value' => is_int($value) ? self::number($value) : $value];
                }
            }

            $total = self::int($anomaly, 'line_total') ?? count($lines);
            $anomalies[] = [
                'title' => self::string($anomaly, 'title') ?? self::ABSENT,
                'titleKey' => null,
                'source' => implode(' · ', array_filter([self::string($anomaly, 'step'), self::string($anomaly, 'object')])),
                'tone' => $blocking ? 'failed' : 'completed-with-warnings',
                'kindKey' => $blocking ? 'run_report.severity.blocking' : 'run_report.severity.warning',
                'countKey' => 'run_report.anomalies.line_count',
                'count' => self::number($total),
                'lines' => $lines,
                'hiddenLines' => max(0, $total - count($lines)),
            ];
        }

        return $anomalies;
    }

    /**
     * @param list<array<string, mixed>> $objects
     * @return list<array{title: ?string, titleKey: ?string, source: string, tone: string, kindKey: string, countKey: string, count: string, lines: list<array{key: string, value: string}>, hiddenLines: int}>
     */
    private static function exclusions(array $objects): array
    {
        $counts = [];
        $labels = [];

        foreach ($objects as $object) {
            $label = self::string($object, 'label') ?? self::ABSENT;

            foreach (is_array($object['excluded'] ?? null) ? $object['excluded'] : [] as $reason => $count) {
                if (is_int($count) && $count > 0) {
                    $counts[$label . ' · ' . $reason] = $count;
                    $labels[$label] = true;
                }
            }
        }

        if ($counts === []) {
            return [];
        }

        arsort($counts);

        return [[
            'title' => null,
            'titleKey' => 'run_report.anomalies.excluded_title',
            'source' => implode(', ', array_keys($labels)),
            'tone' => 'pending',
            'kindKey' => 'run_report.anomalies.excluded_kind',
            'countKey' => 'run_report.anomalies.record_count',
            'count' => self::number(array_sum($counts)),
            'lines' => array_map(
                static fn (string $key, int $count): array => ['key' => $key, 'value' => self::number($count)],
                array_keys($counts),
                array_values($counts),
            ),
            'hiddenLines' => 0,
        ]];
    }

    /**
     * @param list<array<string, mixed>> $objects
     * @return list<array{title: ?string, titleKey: ?string, source: string, tone: string, kindKey: string, countKey: string, count: string, lines: list<array{key: string, value: string}>, hiddenLines: int}>
     */
    private static function rejections(array $objects): array
    {
        $anomalies = [];

        foreach ($objects as $object) {
            $total = self::int($object, 'rejected_total') ?? 0;

            if ($total === 0) {
                continue;
            }

            $lines = [];
            foreach (is_array($object['rejected'] ?? null) ? $object['rejected'] : [] as $record) {
                if (is_array($record)) {
                    $lines[] = ['key' => self::string($record, 'key') ?? self::ABSENT, 'value' => self::string($record, 'message') ?? ''];
                }
            }

            $label = self::string($object, 'label') ?? self::ABSENT;
            $anomalies[] = [
                'title' => $label,
                'titleKey' => 'run_report.anomalies.rejected_title',
                'source' => implode(', ', self::stringList($object['steps'] ?? null)),
                'tone' => 'failed',
                'kindKey' => 'run_report.anomalies.rejected_kind',
                'countKey' => 'run_report.anomalies.record_count',
                'count' => self::number($total),
                'lines' => $lines,
                'hiddenLines' => max(0, $total - count($lines)),
            ];
        }

        return $anomalies;
    }

    /**
     * @param array<string, mixed> $check
     * @return array{step: string, name: string, severityKey: string, outcomeKey: string, tone: string, expected: string, actual: string, samples: list<string>}
     */
    private static function checkRow(array $check): array
    {
        $blocking = ($check['severity'] ?? null) === 'blocking';
        $ok = ($check['outcome'] ?? null) === 'ok';

        return [
            'step' => self::string($check, 'step') ?? self::ABSENT,
            'name' => self::string($check, 'name') ?? self::ABSENT,
            'severityKey' => $blocking ? 'run_report.severity.blocking' : 'run_report.severity.warning',
            'outcomeKey' => $ok ? 'run_report.checks.outcome_ok' : 'run_report.checks.outcome_deviation',
            'tone' => $ok ? 'completed' : ($blocking ? 'failed' : 'completed-with-warnings'),
            'expected' => self::string($check, 'expected') ?? self::ABSENT,
            'actual' => self::string($check, 'actual') ?? self::ABSENT,
            'samples' => self::stringList($check['samples'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $section
     * @return array{title: string, available: bool, message: ?string, lines: list<array{label: string, detail: ?string, status: ?string, tone: string}>}
     */
    private static function sectionRow(array $section): array
    {
        $lines = [];
        foreach (is_array($section['lines'] ?? null) ? $section['lines'] : [] as $line) {
            if (!is_array($line)) {
                continue;
            }

            $status = self::string($line, 'status');
            $lines[] = [
                'label' => self::string($line, 'label') ?? self::ABSENT,
                'detail' => self::string($line, 'detail'),
                'status' => $status,
                'tone' => match (strtolower((string) $status)) {
                    'ok', 'success', 'completed' => 'completed',
                    'warning', 'deviation' => 'completed-with-warnings',
                    'error', 'failed', 'blocking' => 'failed',
                    default => 'pending',
                },
            ];
        }

        return [
            'title' => self::string($section, 'title') ?? self::ABSENT,
            'available' => ($section['available'] ?? true) !== false,
            'message' => self::string($section, 'message'),
            'lines' => $lines,
        ];
    }

    /**
     * @param array<string, mixed> $content
     * @return list<array<string, mixed>>
     */
    private static function rows(array $content, string $key): array
    {
        $rows = $content[$key] ?? null;

        return is_array($rows) ? array_values(array_filter($rows, is_array(...))) : [];
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }

        return array_values(array_map(strval(...), array_filter($values, static fn (mixed $value): bool => is_string($value) || is_int($value))));
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private static function string(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return is_string($value) || is_int($value) ? (string) $value : null;
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private static function int(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private static function date(array $row, string $key): ?DateTimeImmutable
    {
        $value = self::string($row, $key);

        if ($value === null) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private static function number(?int $value): string
    {
        return $value === null ? self::ABSENT : number_format($value, 0, '.', ' ');
    }

    private static function rate(float $perSecond): string
    {
        return number_format($perSecond, $perSecond >= 10 ? 0 : 1, '.', ' ');
    }

    private static function duration(?int $milliseconds): string
    {
        return $milliseconds === null ? self::ABSENT : DurationFormatter::format($milliseconds);
    }

    private static function share(?int $part, ?int $whole): ?int
    {
        return $part === null || $whole === null || $whole <= 0 ? null : (int) round(min(1, $part / $whole) * 100);
    }
}
