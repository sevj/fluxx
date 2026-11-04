<?php

declare(strict_types=1);

namespace Fluxx\Ui;

use DateInterval;
use DateTimeImmutable;
use Fluxx\Reporting\WorkflowStepRunStatistics;
use Fluxx\Repository\WorkflowRunRepository;
use Fluxx\Workflow\WorkflowDefinition;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Builds the statistics shown on the global page and on the statistics tab of a workflow.
 */
final readonly class WorkflowStatisticsViewFactory
{
    public const DEFAULT_RANGE = 'month';

    private const WEEKDAY_KEYS = [
        1 => 'statistics_chart.weekday_mon',
        2 => 'statistics_chart.weekday_tue',
        3 => 'statistics_chart.weekday_wed',
        4 => 'statistics_chart.weekday_thu',
        5 => 'statistics_chart.weekday_fri',
        6 => 'statistics_chart.weekday_sat',
        7 => 'statistics_chart.weekday_sun',
    ];

    private const MONTH_KEYS = [
        1 => 'statistics_chart.month_jan',
        2 => 'statistics_chart.month_feb',
        3 => 'statistics_chart.month_mar',
        4 => 'statistics_chart.month_apr',
        5 => 'statistics_chart.month_may',
        6 => 'statistics_chart.month_jun',
        7 => 'statistics_chart.month_jul',
        8 => 'statistics_chart.month_aug',
        9 => 'statistics_chart.month_sep',
        10 => 'statistics_chart.month_oct',
        11 => 'statistics_chart.month_nov',
        12 => 'statistics_chart.month_dec',
    ];

    public function __construct(
        private WorkflowRunRepository $workflowRunRepository,
        private WorkflowStepRunStatistics $workflowStepRunStatistics,
    ) {
    }

    /**
     * Without a workflow, the statistics cover every run.
     */
    public function build(string $statisticsRange, ?WorkflowDefinition $workflow = null, ?DateTimeImmutable $today = null): WorkflowStatisticsView
    {
        $range = $this->normalizeRange($statisticsRange);
        $today ??= new DateTimeImmutable('today');
        $workflowCode = $workflow?->code();

        $bucketStarts = match ($range) {
            'week' => $this->dailyBucketStarts($today->sub(new DateInterval('P6D')), 7),
            'year' => $this->monthlyBucketStarts($today->modify('first day of this month')->sub(new DateInterval('P11M')), 12),
            default => $this->dailyBucketStarts($today->sub(new DateInterval('P29D')), 30),
        };

        $startAt = $bucketStarts[0];
        $bucketFormat = $range === 'year' ? 'Y-m' : 'Y-m-d';
        $bucketStats = $this->workflowRunRepository->aggregateCreatedSince($startAt, $range === 'year' ? 'month' : 'day', $workflowCode);
        $runStatistics = $this->workflowRunRepository->summarizeCreatedSince($startAt, $workflowCode);
        $stepStatistics = $this->workflowStepRunStatistics->aggregateLatestStepStatisticsSince($startAt, $workflowCode);

        $maxValue = 1;
        $executionTotal = 0;
        $errorTotal = 0;
        $counts = [];

        foreach ($bucketStarts as $index => $bucketStart) {
            $bucket = $bucketStats[$bucketStart->format($bucketFormat)] ?? ['executionCount' => 0, 'errorCount' => 0];
            $counts[$index] = $bucket;
            $executionTotal += $bucket['executionCount'];
            $errorTotal += $bucket['errorCount'];
            $maxValue = max($maxValue, $bucket['executionCount'], $bucket['errorCount']);
        }

        $points = [];

        foreach ($bucketStarts as $index => $bucketStart) {
            $points[] = new WorkflowStatisticsPointView(
                label: $this->bucketLabel($bucketStart, $range),
                axisLabel: $this->bucketAxisLabel($bucketStart, $range, $index, count($bucketStarts)),
                executionCount: $counts[$index]['executionCount'],
                errorCount: $counts[$index]['errorCount'],
                executionHeightPercent: ($counts[$index]['executionCount'] / $maxValue) * 100,
                errorHeightPercent: ($counts[$index]['errorCount'] / $maxValue) * 100,
            );
        }

        return new WorkflowStatisticsView(
            selectedRange: $range,
            ranges: $this->ranges($range),
            points: $points,
            metrics: $this->metrics($runStatistics, $stepStatistics),
            stepMetrics: $workflow !== null ? $this->stepMetrics($workflow, $stepStatistics['steps']) : [],
            executionTotal: $executionTotal,
            errorTotal: $errorTotal,
            maxValue: $maxValue,
            yAxisTicks: $this->yAxisTicks($maxValue),
            workflowCode: $workflowCode,
        );
    }

    /**
     * The placeholder used by the tabs that do not display statistics, which spares their queries.
     */
    public function empty(string $statisticsRange): WorkflowStatisticsView
    {
        $range = $this->normalizeRange($statisticsRange);

        return new WorkflowStatisticsView(
            selectedRange: $range,
            ranges: $this->ranges($range),
            points: [],
            metrics: [],
            stepMetrics: [],
            executionTotal: 0,
            errorTotal: 0,
            maxValue: 1,
            yAxisTicks: [1, 0],
        );
    }

    private function normalizeRange(string $statisticsRange): string
    {
        return in_array($statisticsRange, ['week', 'month', 'year'], true)
            ? $statisticsRange
            : self::DEFAULT_RANGE;
    }

    /**
     * @return list<WorkflowStatisticsRangeView>
     */
    private function ranges(string $range): array
    {
        return [
            new WorkflowStatisticsRangeView('week', 'statistics_chart.range_week', $range === 'week'),
            new WorkflowStatisticsRangeView('month', 'statistics_chart.range_month', $range === 'month'),
            new WorkflowStatisticsRangeView('year', 'statistics_chart.range_year', $range === 'year'),
        ];
    }

    /**
     * @return list<DateTimeImmutable>
     */
    private function dailyBucketStarts(DateTimeImmutable $startAt, int $days): array
    {
        $buckets = [];

        for ($index = 0; $index < $days; ++$index) {
            $buckets[] = $startAt->add(new DateInterval(sprintf('P%dD', $index)));
        }

        return $buckets;
    }

    /**
     * @return list<DateTimeImmutable>
     */
    private function monthlyBucketStarts(DateTimeImmutable $startAt, int $months): array
    {
        $buckets = [];

        for ($index = 0; $index < $months; ++$index) {
            $buckets[] = $startAt->add(new DateInterval(sprintf('P%dM', $index)));
        }

        return $buckets;
    }

    private function bucketLabel(DateTimeImmutable $bucketStart, string $range): TranslatableMessage
    {
        return match ($range) {
            'week' => new TranslatableMessage('statistics_chart.label_weekday', [
                '%weekday%' => $this->weekday($bucketStart),
                '%day%' => $bucketStart->format('d'),
                '%month%' => $this->month($bucketStart),
            ], 'fluxx'),
            'year' => new TranslatableMessage('statistics_chart.label_month', [
                '%month%' => $this->month($bucketStart),
                '%year%' => $bucketStart->format('Y'),
            ], 'fluxx'),
            default => new TranslatableMessage('statistics_chart.label_day', [
                '%day%' => $bucketStart->format('d'),
                '%month%' => $this->month($bucketStart),
                '%year%' => $bucketStart->format('Y'),
            ], 'fluxx'),
        };
    }

    private function bucketAxisLabel(DateTimeImmutable $bucketStart, string $range, int $index, int $count): ?TranslatableMessage
    {
        return match ($range) {
            'week' => $this->weekday($bucketStart),
            'year' => $this->month($bucketStart),
            default => ($index % 5 === 0 || $index === $count - 1)
                ? new TranslatableMessage('statistics_chart.axis_day', [
                    '%day%' => $bucketStart->format('d'),
                    '%month%' => $bucketStart->format('m'),
                ], 'fluxx')
                : null,
        };
    }

    private function weekday(DateTimeImmutable $date): TranslatableMessage
    {
        return new TranslatableMessage(self::WEEKDAY_KEYS[(int) $date->format('N')], [], 'fluxx');
    }

    private function month(DateTimeImmutable $date): TranslatableMessage
    {
        return new TranslatableMessage(self::MONTH_KEYS[(int) $date->format('n')], [], 'fluxx');
    }

    /**
     * @return list<int>
     */
    private function yAxisTicks(int $maxValue): array
    {
        if ($maxValue <= 1) {
            return [1, 0];
        }

        return array_values(array_unique([$maxValue, (int) ceil($maxValue / 2), 0]));
    }

    /**
     * @param array{
     *     runCount: int,
     *     failedCount: int,
     *     partialFailedCount: int,
     *     relaunchCount: int,
     *     durations: list<int>
     * } $runStatistics
     * @param array{
     *     retryRunCount: int,
     *     processedTotal: int,
     *     successTotal: int,
     *     recordErrorTotal: int,
     *     steps: array<string, mixed>
     * } $stepStatistics
     * @return list<WorkflowStatisticsMetricView>
     */
    private function metrics(array $runStatistics, array $stepStatistics): array
    {
        $runCount = $runStatistics['runCount'];

        return [
            new WorkflowStatisticsMetricView('statistics_chart.metric_avg_duration', DurationFormatter::format($this->average($runStatistics['durations']))),
            new WorkflowStatisticsMetricView('statistics_chart.metric_p95_duration', DurationFormatter::format($this->percentile95($runStatistics['durations']))),
            new WorkflowStatisticsMetricView('statistics_chart.metric_failure_rate', $this->percentage($runStatistics['failedCount'], $runCount), $runStatistics['failedCount'] > 0 ? 'error' : 'default'),
            new WorkflowStatisticsMetricView('statistics_chart.metric_partial_failure_rate', $this->percentage($runStatistics['partialFailedCount'], $runCount), $runStatistics['partialFailedCount'] > 0 ? 'warning' : 'default'),
            new WorkflowStatisticsMetricView('statistics_chart.metric_retry_rate', $this->percentage($stepStatistics['retryRunCount'], $runCount), $stepStatistics['retryRunCount'] > 0 ? 'warning' : 'default'),
            new WorkflowStatisticsMetricView('statistics_chart.metric_relaunch_rate', $this->percentage($runStatistics['relaunchCount'], $runCount)),
            new WorkflowStatisticsMetricView('statistics_chart.metric_processed_total', $this->count($stepStatistics['processedTotal'])),
            new WorkflowStatisticsMetricView('statistics_chart.metric_success_total', $this->count($stepStatistics['successTotal'])),
            new WorkflowStatisticsMetricView('statistics_chart.metric_record_errors_total', $this->count($stepStatistics['recordErrorTotal']), $stepStatistics['recordErrorTotal'] > 0 ? 'error' : 'default'),
        ];
    }

    /**
     * @param array<string, array{
     *     durationTotal: int,
     *     durationCount: int,
     *     failureCount: int,
     *     retryCount: int,
     *     idempotenceHitCount: int,
     *     executionCount: int
     * }> $stepStatsByCode
     * @return list<WorkflowStepStatisticsView>
     */
    private function stepMetrics(WorkflowDefinition $workflow, array $stepStatsByCode): array
    {
        $views = [];

        foreach ($workflow->steps() as $step) {
            $stepStats = $stepStatsByCode[$step->code()] ?? null;
            $views[] = new WorkflowStepStatisticsView(
                code: $step->code(),
                name: $step->name(),
                averageDurationMs: ($stepStats['durationCount'] ?? 0) > 0
                    ? (int) floor($stepStats['durationTotal'] / $stepStats['durationCount'])
                    : null,
                failureCount: $stepStats['failureCount'] ?? 0,
                retryCount: $stepStats['retryCount'] ?? 0,
                idempotenceHitCount: $stepStats['idempotenceHitCount'] ?? 0,
                executionCount: $stepStats['executionCount'] ?? 0,
            );
        }

        return $views;
    }

    /**
     * @param list<int> $values
     */
    private function average(array $values): ?int
    {
        if ($values === []) {
            return null;
        }

        return (int) floor(array_sum($values) / count($values));
    }

    /**
     * @param list<int> $values
     */
    private function percentile95(array $values): ?int
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $index = max((int) ceil(count($values) * 0.95) - 1, 0);

        return $values[$index] ?? null;
    }

    private function count(int $value): string
    {
        return number_format($value, 0, '.', "\u{00A0}");
    }

    private function percentage(int $count, int $total): string
    {
        if ($total === 0) {
            return '0%';
        }

        return number_format(($count / $total) * 100, 1, '.', ' ') . '%';
    }
}
