<?php

declare(strict_types=1);

namespace Fluxx\Ui;

use DateInterval;
use DateTimeImmutable;
use Fluxx\Repository\WorkflowRunRepository;
use Fluxx\Repository\WorkflowStepRunRepository;

final readonly class GlobalStatistics
{
    private const DEFAULT_STATISTICS_RANGE = 'month';

    public function __construct(
        private WorkflowRunRepository $workflowRunRepository,
        private WorkflowStepRunRepository $workflowStepRunRepository,
    ) {
    }

    public function build(string $statisticsRange = self::DEFAULT_STATISTICS_RANGE): WorkflowStatisticsView
    {
        $range = $this->normalizeStatisticsRange($statisticsRange);
        $today = new DateTimeImmutable('today');

        $bucketStarts = match ($range) {
            'week' => $this->buildDailyBucketStarts($today->sub(new DateInterval('P6D')), 7),
            'year' => $this->buildMonthlyBucketStarts($today->modify('first day of this month')->sub(new DateInterval('P11M')), 12),
            default => $this->buildDailyBucketStarts($today->sub(new DateInterval('P29D')), 30),
        };

        $startAt = $bucketStarts[0];
        $bucketFormat = $range === 'year' ? 'Y-m' : 'Y-m-d';
        $bucketStats = $this->workflowRunRepository->aggregateCreatedSinceAll($startAt, $range === 'year' ? 'month' : 'day');
        $runStatistics = $this->workflowRunRepository->summarizeCreatedSinceAll($startAt);
        $stepStatistics = $this->workflowStepRunRepository->aggregateLatestStepStatisticsSinceAll($startAt);

        foreach ($bucketStarts as $bucketStart) {
            $bucketStats[$bucketStart->format($bucketFormat)] ??= [
                'executionCount' => 0,
                'errorCount' => 0,
            ];
        }

        $maxValue = 0;
        $executionTotal = 0;
        $errorTotal = 0;

        foreach ($bucketStarts as $bucketStart) {
            $bucketKey = $bucketStart->format($bucketFormat);
            $executionCount = $bucketStats[$bucketKey]['executionCount'];
            $errorCount = $bucketStats[$bucketKey]['errorCount'];
            $executionTotal += $executionCount;
            $errorTotal += $errorCount;
            $maxValue = max($maxValue, $executionCount, $errorCount);
        }

        $maxValue = max($maxValue, 1);
        $points = [];

        foreach ($bucketStarts as $index => $bucketStart) {
            $bucketKey = $bucketStart->format($bucketFormat);
            $executionCount = $bucketStats[$bucketKey]['executionCount'];
            $errorCount = $bucketStats[$bucketKey]['errorCount'];

            $points[] = new WorkflowStatisticsPointView(
                label: $this->formatBucketLabel($bucketStart, $range),
                axisLabel: $this->formatBucketAxisLabel($bucketStart, $range, $index, count($bucketStarts)),
                executionCount: $executionCount,
                errorCount: $errorCount,
                executionHeightPercent: ($executionCount / $maxValue) * 100,
                errorHeightPercent: ($errorCount / $maxValue) * 100,
            );
        }

        return new WorkflowStatisticsView(
            selectedRange: $range,
            ranges: $this->buildStatisticsRanges($range),
            points: $points,
            metrics: $this->buildAdvancedStatisticsMetrics($runStatistics, $stepStatistics),
            stepMetrics: [],
            executionTotal: $executionTotal,
            errorTotal: $errorTotal,
            maxValue: $maxValue,
            yAxisTicks: $this->buildYAxisTicks($maxValue),
        );
    }

    private function normalizeStatisticsRange(string $statisticsRange): string
    {
        return in_array($statisticsRange, ['week', 'month', 'year'], true)
            ? $statisticsRange
            : self::DEFAULT_STATISTICS_RANGE;
    }

    /**
     * @return list<WorkflowStatisticsRangeView>
     */
    private function buildStatisticsRanges(string $range): array
    {
        return [
            new WorkflowStatisticsRangeView('week', 'statistics_index.range_week', $range === 'week'),
            new WorkflowStatisticsRangeView('month', 'statistics_index.range_month', $range === 'month'),
            new WorkflowStatisticsRangeView('year', 'statistics_index.range_year', $range === 'year'),
        ];
    }

    /**
     * @return list<DateTimeImmutable>
     */
    private function buildDailyBucketStarts(DateTimeImmutable $startAt, int $days): array
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
    private function buildMonthlyBucketStarts(DateTimeImmutable $startAt, int $months): array
    {
        $buckets = [];

        for ($index = 0; $index < $months; ++$index) {
            $buckets[] = $startAt->add(new DateInterval(sprintf('P%dM', $index)));
        }

        return $buckets;
    }

    private function formatBucketLabel(DateTimeImmutable $bucketStart, string $range): string
    {
        return match ($range) {
            'week' => $bucketStart->format('D d M'),
            'year' => $bucketStart->format('M Y'),
            default => $bucketStart->format('d M Y'),
        };
    }

    private function formatBucketAxisLabel(DateTimeImmutable $bucketStart, string $range, int $index, int $count): string
    {
        return match ($range) {
            'week' => $bucketStart->format('D'),
            'year' => $bucketStart->format('M'),
            default => ($index % 5 === 0 || $index === $count - 1) ? $bucketStart->format('d/m') : '',
        };
    }

    /**
     * @return list<int>
     */
    private function buildYAxisTicks(int $maxValue): array
    {
        if ($maxValue <= 1) {
            return [1, 0];
        }

        $mid = (int) ceil($maxValue / 2);

        return array_values(array_unique([$maxValue, $mid, 0]));
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
     *     recordErrorTotal: int
     * } $stepStatistics
     * @return list<WorkflowStatisticsMetricView>
     */
    private function buildAdvancedStatisticsMetrics(array $runStatistics, array $stepStatistics): array
    {
        $runCount = $runStatistics['runCount'];

        return [
            new WorkflowStatisticsMetricView('statistics_index.metric_avg_duration', $this->formatDurationMetric($this->average($runStatistics['durations']))),
            new WorkflowStatisticsMetricView('statistics_index.metric_p95_duration', $this->formatDurationMetric($this->percentile95($runStatistics['durations']))),
            new WorkflowStatisticsMetricView('statistics_index.metric_failure_rate', $this->formatPercentageMetric($runStatistics['failedCount'], $runCount), $runStatistics['failedCount'] > 0 ? 'error' : 'default'),
            new WorkflowStatisticsMetricView('statistics_index.metric_partial_failure_rate', $this->formatPercentageMetric($runStatistics['partialFailedCount'], $runCount), $runStatistics['partialFailedCount'] > 0 ? 'warning' : 'default'),
            new WorkflowStatisticsMetricView('statistics_index.metric_retry_rate', $this->formatPercentageMetric($stepStatistics['retryRunCount'], $runCount), $stepStatistics['retryRunCount'] > 0 ? 'warning' : 'default'),
            new WorkflowStatisticsMetricView('statistics_index.metric_relaunch_rate', $this->formatPercentageMetric($runStatistics['relaunchCount'], $runCount)),
            new WorkflowStatisticsMetricView('statistics_index.metric_processed_total', (string) $stepStatistics['processedTotal']),
            new WorkflowStatisticsMetricView('statistics_index.metric_success_total', (string) $stepStatistics['successTotal']),
            new WorkflowStatisticsMetricView('statistics_index.metric_record_errors_total', (string) $stepStatistics['recordErrorTotal'], $stepStatistics['recordErrorTotal'] > 0 ? 'error' : 'default'),
        ];
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

    private function formatDurationMetric(?int $durationMs): string
    {
        if ($durationMs === null) {
            return '-';
        }

        if ($durationMs >= 1000) {
            return number_format($durationMs / 1000, 1, '.', ' ') . ' s';
        }

        return $durationMs . ' ms';
    }

    private function formatPercentageMetric(int $count, int $total): string
    {
        if ($total === 0) {
            return '0%';
        }

        return number_format(($count / $total) * 100, 1, '.', ' ') . '%';
    }
}
