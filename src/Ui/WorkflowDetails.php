<?php

declare(strict_types=1);

namespace Fluxx\Ui;

use DateInterval;
use DateTimeImmutable;
use Fluxx\Entity\Enum\WorkflowRunStatus;
use Fluxx\Repository\WorkflowRunRepository;
use Fluxx\Repository\WorkflowStepRunRepository;
use Fluxx\StepType\StepTypeRegistry;
use Fluxx\Workflow\SynchronizationRegistry;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;
use Fluxx\Workflow\WorkflowDefinition;

final readonly class WorkflowDetails
{
    private const DEFAULT_PER_PAGE = 10;
    private const DEFAULT_STATISTICS_RANGE = 'month';

    public function __construct(
        private SynchronizationRegistry $registry,
        private StepTypeRegistry $stepTypeRegistry,
        private WorkflowRunRepository $workflowRunRepository,
        private WorkflowStepRunRepository $workflowStepRunRepository,
    ) {
    }

    public function forCode(
        string $workflowCode,
        int $page = 1,
        int $perPage = self::DEFAULT_PER_PAGE,
        string $statisticsRange = self::DEFAULT_STATISTICS_RANGE,
        ?WorkflowRunFilters $executionFilters = null,
    ): WorkflowDetailView
    {
        $definition = $this->registry->get($workflowCode)->definition();
        $steps = $this->buildStepDefinitionViews($definition);
        $graph = $this->buildGraphRows($steps);
        $statisticsRange = $this->normalizeStatisticsRange($statisticsRange);
        $executionFilters ??= new WorkflowRunFilters(workflowCode: $definition->code());

        return new WorkflowDetailView(
            code: $definition->code(),
            name: $definition->name(),
            sourceSystem: $definition->sourceSystem(),
            targetSystem: $definition->targetSystem(),
            graphColumnCount: $graph['columnCount'],
            graphRowCount: $graph['rowCount'],
            executionCount: $this->workflowRunRepository->countByWorkflowName($definition->code()),
            errorCount: $this->workflowRunRepository->countErroredByWorkflowName($definition->code()),
            lastExecutionAt: $this->workflowRunRepository->findLatestOneByWorkflowName($definition->code())?->createdAt(),
            lastErrorAt: $this->workflowRunRepository->findLatestErrorAtByWorkflowName($definition->code()),
            steps: $steps,
            stepRows: $graph['rows'],
            graphEdges: $graph['edges'],
            executionPage: $this->buildExecutionPage($definition, $page, $perPage, $executionFilters),
            statistics: $this->buildStatisticsView($definition, $statisticsRange),
        );
    }

    public function overviewForCode(string $workflowCode): WorkflowOverview
    {
        $definition = $this->registry->get($workflowCode)->definition();

        return new WorkflowOverview(
            code: $definition->code(),
            name: $definition->name(),
            sourceSystem: $definition->sourceSystem(),
            targetSystem: $definition->targetSystem(),
            lastExecutionAt: $this->workflowRunRepository->findLatestOneByWorkflowName($definition->code())?->createdAt(),
            executionCount: $this->workflowRunRepository->countByWorkflowName($definition->code()),
            errorCount: $this->workflowRunRepository->countErroredByWorkflowName($definition->code()),
            lastErrorAt: $this->workflowRunRepository->findLatestErrorAtByWorkflowName($definition->code()),
        );
    }

    /**
     * @return list<WorkflowStepDefinitionView>
     */
    private function buildStepDefinitionViews(WorkflowDefinition $definition): array
    {
        $levels = [];

        foreach ($definition->steps() as $step) {
            $levels[$step->code()] = $this->resolveStepLevel($definition, $step->code(), $levels);
        }

        return array_map(
            function (\Fluxx\Workflow\WorkflowStepDefinition $step) use ($levels): WorkflowStepDefinitionView {
                $type = $this->stepTypeRegistry->get($step->type());

                return new WorkflowStepDefinitionView(
                    $step->type(),
                    $type->label(),
                    $type->toneClass(),
                    $type->toneStyle(),
                    $step->code(),
                    $step->name(),
                    $step->dependsOn(),
                    $levels[$step->code()],
                );
            },
            $definition->steps(),
        );
    }

    /**
     * @param array<string, int> $levels
     */
    private function resolveStepLevel(WorkflowDefinition $definition, string $stepCode, array &$levels): int
    {
        if (isset($levels[$stepCode])) {
            return $levels[$stepCode];
        }

        $step = $definition->step($stepCode);

        if ($step->dependsOn() === []) {
            return $levels[$stepCode] = 0;
        }

        $level = 0;

        foreach ($step->dependsOn() as $dependencyCode) {
            $level = max($level, $this->resolveStepLevel($definition, $dependencyCode, $levels) + 1);
        }

        return $levels[$stepCode] = $level;
    }

    /**
     * @param list<WorkflowStepDefinitionView> $steps
     * @return array{rows: list<WorkflowStepRowView>, edges: list<WorkflowGraphEdgeView>, columnCount: int, rowCount: int}
     */
    private function buildGraphRows(array $steps): array
    {
        $stepMap = [];
        $nodeMap = [];
        $rows = [];
        $columnCount = 0;

        foreach ($steps as $step) {
            $stepMap[$step->code()] = $step;
            $rows[$step->level()][] = $step;
            $columnCount = max($columnCount, $step->level() + 1);
        }

        $branchPaths = $this->computeBranchPaths($steps, $stepMap);
        $lanePaths = $this->computeTerminalLanePaths($branchPaths);
        $rowCount = max(count($lanePaths), 1);
        $graphRows = [];

        ksort($rows);

        $rowLayouts = array_map(fn (array $row): string => $this->resolveRowLayout($row), $rows);
        $rowIndex = 0;

        foreach ($rows as $row) {
            $nodes = array_map(
                fn (WorkflowStepDefinitionView $step): WorkflowGraphNodeView => $this->createGraphNode($step, $branchPaths, $lanePaths, $columnCount),
                $row,
            );

            usort(
                $nodes,
                static fn (WorkflowGraphNodeView $left, WorkflowGraphNodeView $right): int => [$left->rowStart(), $left->step()->code()]
                    <=> [$right->rowStart(), $right->step()->code()],
            );

            foreach ($nodes as $node) {
                $nodeMap[$node->step()->code()] = $node;
            }

            $graphRows[] = new WorkflowStepRowView(
                nodes: $nodes,
                layout: $rowLayouts[$rowIndex],
                connectsToMergeNext: ($rowLayouts[$rowIndex + 1] ?? null) === 'merge',
            );

            ++$rowIndex;
        }

        return [
            'rows' => $graphRows,
            'edges' => $this->buildGraphEdges($steps, $nodeMap),
            'columnCount' => max($columnCount, 1),
            'rowCount' => max($rowCount, 1),
        ];
    }

    /**
     * @param list<WorkflowStepDefinitionView> $steps
     * @param array<string, WorkflowGraphNodeView> $nodeMap
     * @return list<WorkflowGraphEdgeView>
     */
    private function buildGraphEdges(array $steps, array $nodeMap): array
    {
        $edges = [];

        foreach ($steps as $step) {
            foreach ($step->dependsOn() as $dependencyCode) {
                if (!isset($nodeMap[$dependencyCode], $nodeMap[$step->code()])) {
                    continue;
                }

                $edges[] = new WorkflowGraphEdgeView(
                    from: $nodeMap[$dependencyCode],
                    to: $nodeMap[$step->code()],
                );
            }
        }

        return $edges;
    }

    /**
     * @param list<WorkflowStepDefinitionView> $steps
     * @param array<string, WorkflowStepDefinitionView> $stepMap
     * @return array<string, list<int>>
     */
    private function computeBranchPaths(array $steps, array $stepMap): array
    {
        $branchPaths = [];

        foreach ($steps as $step) {
            $this->resolveBranchPath($step, $stepMap, $branchPaths);
        }

        return $branchPaths;
    }

    /**
     * @param array<string, WorkflowStepDefinitionView> $stepMap
     * @param array<string, list<int>> $branchPaths
     * @return list<int>
     */
    private function resolveBranchPath(
        WorkflowStepDefinitionView $step,
        array $stepMap,
        array &$branchPaths,
    ): array {
        if (isset($branchPaths[$step->code()])) {
            return $branchPaths[$step->code()];
        }

        if ($step->dependsOn() === []) {
            return $branchPaths[$step->code()] = [];
        }

        if (count($step->dependsOn()) > 1) {
            $dependencyPaths = array_map(
                fn (string $dependencyCode): array => $this->resolveBranchPath($stepMap[$dependencyCode], $stepMap, $branchPaths),
                $step->dependsOn(),
            );

            return $branchPaths[$step->code()] = $this->commonBranchPrefix($dependencyPaths);
        }

        $dependencyCode = $step->dependsOn()[0];
        $parent = $stepMap[$dependencyCode];
        $parentPath = $this->resolveBranchPath($parent, $stepMap, $branchPaths);
        $siblings = array_values(array_filter(
            $steps = array_values($stepMap),
            static fn (WorkflowStepDefinitionView $candidate): bool => $candidate->dependsOn() === [$dependencyCode],
        ));

        if (count($siblings) <= 1) {
            return $branchPaths[$step->code()] = $parentPath;
        }

        $siblingCodes = array_map(
            static fn (WorkflowStepDefinitionView $candidate): string => $candidate->code(),
            $siblings,
        );
        $branchIndex = array_search($step->code(), $siblingCodes, true);

        return $branchPaths[$step->code()] = [
            ...$parentPath,
            is_int($branchIndex) ? $branchIndex : 0,
        ];
    }

    /**
     * @param array<string, list<int>> $branchPaths
     * @return list<list<int>>
     */
    private function computeTerminalLanePaths(array $branchPaths): array
    {
        $paths = [];

        foreach ($branchPaths as $path) {
            if ($path === []) {
                continue;
            }

            $paths[implode('.', $path)] = $path;
        }

        $paths = array_values($paths);

        if ($paths === []) {
            return [];
        }

        $terminalPaths = [];

        foreach ($paths as $index => $path) {
            $isPrefixOfAnother = false;

            foreach ($paths as $otherIndex => $otherPath) {
                if ($index === $otherIndex) {
                    continue;
                }

                if ($this->isPathPrefix($path, $otherPath)) {
                    $isPrefixOfAnother = true;
                    break;
                }
            }

            if (!$isPrefixOfAnother) {
                $terminalPaths[] = $path;
            }
        }

        usort($terminalPaths, static fn (array $left, array $right): int => $left <=> $right);

        return $terminalPaths;
    }

    /**
     * @param array<string, list<int>> $branchPaths
     * @param list<list<int>> $lanePaths
     */
    private function createGraphNode(
        WorkflowStepDefinitionView $step,
        array $branchPaths,
        array $lanePaths,
        int $columnCount,
    ): WorkflowGraphNodeView {
        $path = $branchPaths[$step->code()] ?? [];

        if ($path === [] || $lanePaths === []) {
            return new WorkflowGraphNodeView(
                step: $step,
                rowStart: 1,
                columnStart: $step->level() + 1,
                rowSpan: max(count($lanePaths), 1),
            );
        }

        $coveredRows = [];

        foreach ($lanePaths as $index => $lanePath) {
            if ($this->isPathPrefix($path, $lanePath)) {
                $coveredRows[] = $index + 1;
            }
        }

        if ($coveredRows === []) {
            $coveredRows[] = 1;
        }

        return new WorkflowGraphNodeView(
            step: $step,
            rowStart: min($coveredRows),
            columnStart: $step->level() + 1,
            rowSpan: count($coveredRows),
        );
    }

    /**
     * @param list<WorkflowStepDefinitionView> $row
     */
    private function resolveRowLayout(array $row): string
    {
        if (count($row) === 1) {
            return count($row[0]->dependsOn()) > 1 ? 'merge' : 'single';
        }

        $parentCodes = [];

        foreach ($row as $step) {
            if (count($step->dependsOn()) !== 1) {
                return 'parallel';
            }

            $parentCodes[] = $step->dependsOn()[0];
        }

        return count(array_unique($parentCodes)) === 1 ? 'split' : 'parallel';
    }

    /**
     * @param list<list<int>> $paths
     * @return list<int>
     */
    private function commonBranchPrefix(array $paths): array
    {
        if ($paths === []) {
            return [];
        }

        $prefix = $paths[0];

        foreach ($paths as $path) {
            $maxIndex = min(count($prefix), count($path));
            $shared = [];

            for ($index = 0; $index < $maxIndex; ++$index) {
                if ($prefix[$index] !== $path[$index]) {
                    break;
                }

                $shared[] = $prefix[$index];
            }

            $prefix = $shared;
        }

        return $prefix;
    }

    /**
     * @param list<int> $prefix
     * @param list<int> $path
     */
    private function isPathPrefix(array $prefix, array $path): bool
    {
        if (count($prefix) > count($path)) {
            return false;
        }

        foreach ($prefix as $index => $value) {
            if (!isset($path[$index]) || $path[$index] !== $value) {
                return false;
            }
        }

        return true;
    }

    private function buildExecutionPage(
        WorkflowDefinition $definition,
        int $page,
        int $perPage,
        WorkflowRunFilters $executionFilters,
    ): WorkflowExecutionPage
    {
        $page = max($page, 1);
        $perPage = max($perPage, 1);
        $totalItems = $this->workflowRunRepository->countByFilters($executionFilters);
        $totalPages = max((int) ceil($totalItems / $perPage), 1);
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $runs = $this->workflowRunRepository->findPaginatedByFilters($executionFilters, $perPage, $offset);
        $stepRunsByRunId = $this->workflowStepRunRepository->findByWorkflowRunsGrouped($runs);

        $items = array_map(
            function (WorkflowRun $run) use ($definition, $stepRunsByRunId): WorkflowExecutionOverview {
                $steps = $this->buildExecutionSteps($definition, $stepRunsByRunId[$run->runId()] ?? []);

                return new WorkflowExecutionOverview(
                    runId: $run->runId(),
                    trigger: $run->trigger(),
                    status: $run->status()->value,
                    lockKey: $run->lockKey(),
                    lockScope: $run->lockScope()?->value,
                    relaunchMode: is_string($run->relaunchMetadata()['mode'] ?? null) ? $run->relaunchMetadata()['mode'] : null,
                    originalRunId: is_string($run->relaunchMetadata()['original_run_id'] ?? null) ? $run->relaunchMetadata()['original_run_id'] : null,
                    restartStepCode: is_string($run->relaunchMetadata()['restart_step_code'] ?? null) ? $run->relaunchMetadata()['restart_step_code'] : null,
                    createdAt: $run->createdAt(),
                    startedAt: $run->startedAt(),
                    finishedAt: $run->finishedAt(),
                    errorMessage: $run->errorMessage(),
                    errorCategory: is_string($run->errorPayload()['category'] ?? null) ? $run->errorPayload()['category'] : null,
                    steps: $steps,
                    stepMap: $this->buildExecutionStepMap($steps),
                );
            },
            $runs,
        );

        return new WorkflowExecutionPage(
            items: $items,
            currentPage: $page,
            perPage: $perPage,
            totalItems: $totalItems,
            totalPages: $totalPages,
        );
    }

    /**
     * @param list<WorkflowStepRun> $stepRuns
     * @return list<WorkflowExecutionStepOverview>
     */
    private function buildExecutionSteps(WorkflowDefinition $definition, array $stepRuns): array
    {
        $stepRunMap = [];

        foreach ($stepRuns as $stepRun) {
            $stepRunMap[$stepRun->stepName()] = $stepRun;
        }

        return array_map(
            fn (\Fluxx\Workflow\WorkflowStepDefinition $step): WorkflowExecutionStepOverview => $this->buildExecutionStepOverview(
                $step->type(),
                $step->code(),
                $step->name(),
                $stepRunMap[$step->code()] ?? null,
            ),
            $definition->steps(),
        );
    }

    private function buildExecutionStepOverview(
        string $type,
        string $code,
        string $name,
        ?WorkflowStepRun $stepRun,
    ): WorkflowExecutionStepOverview {
        $stepType = $this->stepTypeRegistry->get($type);

        return new WorkflowExecutionStepOverview(
            type: $type,
            typeLabel: $stepType->label(),
            typeTone: $stepType->toneClass(),
            typeToneStyle: $stepType->toneStyle(),
            code: $code,
            name: $name,
            status: $stepRun?->status()->value ?? 'pending',
            processedCount: $stepRun?->processedCount() ?? 0,
            successCount: $stepRun?->successCount() ?? 0,
            errorCount: $stepRun?->errorCount() ?? 0,
            durationMs: $stepRun?->durationMs(),
            memoryPeakBytes: $stepRun?->memoryPeakBytes(),
            idempotenceKey: $stepRun?->idempotenceKey(),
            deduplicationStatus: $stepRun?->deduplicationStatus()->value ?? 'none',
            deduplicatedFromRunId: $stepRun?->deduplicatedFromStepRun()?->workflowRun()->runId(),
        );
    }

    /**
     * @param list<WorkflowExecutionStepOverview> $steps
     * @return array<string, WorkflowExecutionStepOverview>
     */
    private function buildExecutionStepMap(array $steps): array
    {
        $stepMap = [];

        foreach ($steps as $step) {
            $stepMap[$step->code()] = $step;
        }

        return $stepMap;
    }

    private function normalizeStatisticsRange(string $statisticsRange): string
    {
        return in_array($statisticsRange, ['week', 'month', 'year'], true)
            ? $statisticsRange
            : self::DEFAULT_STATISTICS_RANGE;
    }

    private function buildStatisticsView(WorkflowDefinition $definition, string $statisticsRange): WorkflowStatisticsView
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
        $runs = $this->workflowRunRepository->findCreatedSinceByWorkflowName($definition->code(), $startAt);
        $bucketStats = [];

        foreach ($bucketStarts as $bucketStart) {
            $bucketStats[$bucketStart->format($bucketFormat)] = [
                'executionCount' => 0,
                'errorCount' => 0,
            ];
        }

        foreach ($runs as $run) {
            $bucketKey = $run->createdAt()->format($bucketFormat);

            if (!isset($bucketStats[$bucketKey])) {
                continue;
            }

            ++$bucketStats[$bucketKey]['executionCount'];

            if (in_array($run->status(), [WorkflowRunStatus::Failed, WorkflowRunStatus::PartiallyFailed], true)) {
                ++$bucketStats[$bucketKey]['errorCount'];
            }
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
        $stepRunsByRunId = $this->workflowStepRunRepository->findByWorkflowRunsGrouped($runs);

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
            ranges: [
                new WorkflowStatisticsRangeView('week', 'workflow_show.range_week', $range === 'week'),
                new WorkflowStatisticsRangeView('month', 'workflow_show.range_month', $range === 'month'),
                new WorkflowStatisticsRangeView('year', 'workflow_show.range_year', $range === 'year'),
            ],
            points: $points,
            metrics: $this->buildAdvancedStatisticsMetrics($runs, $stepRunsByRunId),
            stepMetrics: $this->buildStepStatistics($definition, $stepRunsByRunId),
            executionTotal: $executionTotal,
            errorTotal: $errorTotal,
            maxValue: $maxValue,
            yAxisTicks: $this->buildYAxisTicks($maxValue),
        );
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
     * @param list<WorkflowRun> $runs
     * @param array<string, list<WorkflowStepRun>> $stepRunsByRunId
     * @return list<WorkflowStatisticsMetricView>
     */
    private function buildAdvancedStatisticsMetrics(array $runs, array $stepRunsByRunId): array
    {
        $durations = [];
        $failedCount = 0;
        $partialFailedCount = 0;
        $retryRunCount = 0;
        $relaunchCount = 0;
        $processedTotal = 0;
        $successTotal = 0;
        $recordErrorTotal = 0;

        foreach ($runs as $run) {
            if ($run->startedAt() !== null && $run->finishedAt() !== null) {
                $durations[] = max(0, ($run->finishedAt()->getTimestamp() - $run->startedAt()->getTimestamp()) * 1000);
            }

            if ($run->status() === WorkflowRunStatus::Failed) {
                ++$failedCount;
            }

            if ($run->status() === WorkflowRunStatus::PartiallyFailed) {
                ++$partialFailedCount;
            }

            if ($run->relaunchMetadata() !== null) {
                ++$relaunchCount;
            }

            $latestStepRuns = $this->latestStepRunsForStatistics($stepRunsByRunId[$run->runId()] ?? []);
            $hasRetry = false;

            foreach ($latestStepRuns as $stepRun) {
                if ($stepRun->retryCount() > 0) {
                    $hasRetry = true;
                }

                $processedTotal += $stepRun->processedCount();
                $successTotal += $stepRun->successCount();
                $recordErrorTotal += $stepRun->errorCount();
            }

            if ($hasRetry) {
                ++$retryRunCount;
            }
        }

        $runCount = count($runs);

        return [
            new WorkflowStatisticsMetricView('workflow_show.metric_avg_duration', $this->formatDurationMetric($this->average($durations))),
            new WorkflowStatisticsMetricView('workflow_show.metric_p95_duration', $this->formatDurationMetric($this->percentile95($durations))),
            new WorkflowStatisticsMetricView('workflow_show.metric_failure_rate', $this->formatPercentageMetric($failedCount, $runCount), $failedCount > 0 ? 'error' : 'default'),
            new WorkflowStatisticsMetricView('workflow_show.metric_partial_failure_rate', $this->formatPercentageMetric($partialFailedCount, $runCount), $partialFailedCount > 0 ? 'warning' : 'default'),
            new WorkflowStatisticsMetricView('workflow_show.metric_retry_rate', $this->formatPercentageMetric($retryRunCount, $runCount), $retryRunCount > 0 ? 'warning' : 'default'),
            new WorkflowStatisticsMetricView('workflow_show.metric_relaunch_rate', $this->formatPercentageMetric($relaunchCount, $runCount)),
            new WorkflowStatisticsMetricView('workflow_show.metric_processed_total', (string) $processedTotal),
            new WorkflowStatisticsMetricView('workflow_show.metric_success_total', (string) $successTotal),
            new WorkflowStatisticsMetricView('workflow_show.metric_record_errors_total', (string) $recordErrorTotal, $recordErrorTotal > 0 ? 'error' : 'default'),
        ];
    }

    /**
     * @param array<string, list<WorkflowStepRun>> $stepRunsByRunId
     * @return list<WorkflowStepStatisticsView>
     */
    private function buildStepStatistics(WorkflowDefinition $definition, array $stepRunsByRunId): array
    {
        $stats = [];

        foreach ($definition->steps() as $step) {
            $stats[$step->code()] = [
                'name' => $step->name(),
                'durationTotal' => 0,
                'durationCount' => 0,
                'failureCount' => 0,
                'retryCount' => 0,
                'idempotenceHitCount' => 0,
                'executionCount' => 0,
            ];
        }

        foreach ($stepRunsByRunId as $stepRuns) {
            foreach ($this->latestStepRunsForStatistics($stepRuns) as $stepRun) {
                if (!isset($stats[$stepRun->stepName()])) {
                    continue;
                }

                $stats[$stepRun->stepName()]['retryCount'] += $stepRun->retryCount();
                ++$stats[$stepRun->stepName()]['executionCount'];

                if ($stepRun->durationMs() !== null) {
                    $stats[$stepRun->stepName()]['durationTotal'] += $stepRun->durationMs();
                    ++$stats[$stepRun->stepName()]['durationCount'];
                }

                if ($stepRun->status() === \Fluxx\Entity\Enum\WorkflowStepRunStatus::Failed) {
                    ++$stats[$stepRun->stepName()]['failureCount'];
                }

                if ($stepRun->deduplicationStatus()->value !== 'none') {
                    ++$stats[$stepRun->stepName()]['idempotenceHitCount'];
                }
            }
        }

        $views = [];

        foreach ($definition->steps() as $step) {
            $stepStats = $stats[$step->code()];
            $views[] = new WorkflowStepStatisticsView(
                code: $step->code(),
                name: $stepStats['name'],
                averageDurationMs: $stepStats['durationCount'] > 0
                    ? (int) floor($stepStats['durationTotal'] / $stepStats['durationCount'])
                    : null,
                failureCount: $stepStats['failureCount'],
                retryCount: $stepStats['retryCount'],
                idempotenceHitCount: $stepStats['idempotenceHitCount'],
                executionCount: $stepStats['executionCount'],
            );
        }

        return $views;
    }

    /**
     * @param list<WorkflowStepRun> $stepRuns
     * @return array<string, WorkflowStepRun>
     */
    private function latestStepRunsForStatistics(array $stepRuns): array
    {
        $latest = [];

        foreach ($stepRuns as $stepRun) {
            $latest[$stepRun->stepName()] = $stepRun;
        }

        return $latest;
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
