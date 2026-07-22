<?php

declare(strict_types=1);

namespace Fluxx\Ui;

use DateInterval;
use DateTimeImmutable;
use Fluxx\Repository\WorkflowRunRepository;
use Fluxx\Repository\WorkflowStepRunRepository;
use Fluxx\StepType\StepTypeRegistry;
use Fluxx\Workflow\SynchronizationRegistry;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Workflow\WorkflowDefinition;
use InvalidArgumentException;

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
        $statisticsRange = $this->normalizeStatisticsRange($statisticsRange);
        $executionFilters ??= new WorkflowRunFilters(workflowCode: $definition->code());
        $steps = $this->buildStepDefinitionViews($definition);
        $graph = $this->buildGraphRows($steps);

        return $this->createWorkflowDetailView(
            definition: $definition,
            steps: $steps,
            graph: $graph,
            executionPage: $this->buildExecutionPage($definition, $page, $perPage, $executionFilters),
            statistics: $this->buildStatisticsView($definition, $statisticsRange),
            withOverviewMetrics: true,
        );
    }

    public function forTab(
        string $workflowCode,
        string $tab,
        int $page = 1,
        int $perPage = self::DEFAULT_PER_PAGE,
        string $statisticsRange = self::DEFAULT_STATISTICS_RANGE,
        ?WorkflowRunFilters $executionFilters = null,
    ): WorkflowDetailView
    {
        $definition = $this->registry->get($workflowCode)->definition();
        $statisticsRange = $this->normalizeStatisticsRange($statisticsRange);
        $executionFilters ??= new WorkflowRunFilters(workflowCode: $definition->code());

        return match ($tab) {
            'steps' => $this->buildStepsTabView($definition, $page, $perPage, $statisticsRange),
            'executions' => $this->buildExecutionsTabView($definition, $page, $perPage, $statisticsRange, $executionFilters),
            'statistics' => $this->buildStatisticsTabView($definition, $page, $perPage, $statisticsRange),
            default => throw new InvalidArgumentException(sprintf('Workflow tab "%s" was not found.', $tab)),
        };
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

    private function buildStepsTabView(
        WorkflowDefinition $definition,
        int $page,
        int $perPage,
        string $statisticsRange,
    ): WorkflowDetailView {
        $steps = $this->buildStepDefinitionViews($definition);
        $graph = $this->buildGraphRows($steps);

        return $this->createWorkflowDetailView(
            definition: $definition,
            steps: $steps,
            graph: $graph,
            executionPage: $this->emptyExecutionPage($page, $perPage),
            statistics: $this->emptyStatisticsView($statisticsRange),
        );
    }

    private function buildExecutionsTabView(
        WorkflowDefinition $definition,
        int $page,
        int $perPage,
        string $statisticsRange,
        WorkflowRunFilters $executionFilters,
    ): WorkflowDetailView {
        $steps = $this->buildStepDefinitionViews($definition);
        $graph = $this->buildGraphRows($steps);

        return $this->createWorkflowDetailView(
            definition: $definition,
            steps: $steps,
            graph: $graph,
            executionPage: $this->buildExecutionPage($definition, $page, $perPage, $executionFilters),
            statistics: $this->emptyStatisticsView($statisticsRange),
        );
    }

    private function buildStatisticsTabView(
        WorkflowDefinition $definition,
        int $page,
        int $perPage,
        string $statisticsRange,
    ): WorkflowDetailView {
        return $this->createWorkflowDetailView(
            definition: $definition,
            steps: [],
            graph: $this->emptyGraph(),
            executionPage: $this->emptyExecutionPage($page, $perPage),
            statistics: $this->buildStatisticsView($definition, $statisticsRange),
        );
    }

    /**
     * @param list<WorkflowStepDefinitionView> $steps
     * @param array{rows: list<WorkflowStepRowView>, edges: list<WorkflowGraphEdgeView>, columnCount: int, rowCount: int} $graph
     */
    private function createWorkflowDetailView(
        WorkflowDefinition $definition,
        array $steps,
        array $graph,
        WorkflowExecutionPage $executionPage,
        WorkflowStatisticsView $statistics,
        bool $withOverviewMetrics = false,
    ): WorkflowDetailView {
        $overviewMetrics = $withOverviewMetrics
            ? $this->loadOverviewMetrics($definition)
            : [
                'executionCount' => 0,
                'errorCount' => 0,
                'lastExecutionAt' => null,
                'lastErrorAt' => null,
            ];

        return new WorkflowDetailView(
            code: $definition->code(),
            name: $definition->name(),
            sourceSystem: $definition->sourceSystem(),
            targetSystem: $definition->targetSystem(),
            graphColumnCount: $graph['columnCount'],
            graphRowCount: $graph['rowCount'],
            executionCount: $overviewMetrics['executionCount'],
            errorCount: $overviewMetrics['errorCount'],
            lastExecutionAt: $overviewMetrics['lastExecutionAt'],
            lastErrorAt: $overviewMetrics['lastErrorAt'],
            steps: $steps,
            stepRows: $graph['rows'],
            graphEdges: $graph['edges'],
            executionPage: $executionPage,
            statistics: $statistics,
        );
    }

    /**
     * @return array{
     *     executionCount: int,
     *     errorCount: int,
     *     lastExecutionAt: ?DateTimeImmutable,
     *     lastErrorAt: ?DateTimeImmutable
     * }
     */
    private function loadOverviewMetrics(WorkflowDefinition $definition): array
    {
        return [
            'executionCount' => $this->workflowRunRepository->countByWorkflowName($definition->code()),
            'errorCount' => $this->workflowRunRepository->countErroredByWorkflowName($definition->code()),
            'lastExecutionAt' => $this->workflowRunRepository->findLatestOneByWorkflowName($definition->code())?->createdAt(),
            'lastErrorAt' => $this->workflowRunRepository->findLatestErrorAtByWorkflowName($definition->code()),
        ];
    }

    /**
     * @return array{rows: list<WorkflowStepRowView>, edges: list<WorkflowGraphEdgeView>, columnCount: int, rowCount: int}
     */
    private function emptyGraph(): array
    {
        return [
            'rows' => [],
            'edges' => [],
            'columnCount' => 1,
            'rowCount' => 1,
        ];
    }

    private function emptyExecutionPage(int $page, int $perPage): WorkflowExecutionPage
    {
        $page = max($page, 1);
        $perPage = max($perPage, 1);

        return new WorkflowExecutionPage(
            items: [],
            currentPage: $page,
            perPage: $perPage,
            totalItems: 0,
            totalPages: $page,
        );
    }

    private function emptyStatisticsView(string $statisticsRange): WorkflowStatisticsView
    {
        $range = $this->normalizeStatisticsRange($statisticsRange);

        return new WorkflowStatisticsView(
            selectedRange: $range,
            ranges: $this->buildStatisticsRanges($range),
            points: [],
            metrics: [],
            stepMetrics: [],
            executionTotal: 0,
            errorTotal: 0,
            maxValue: 1,
            yAxisTicks: [1, 0],
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
        $stepRunMap = $this->workflowStepRunRepository->findLatestByWorkflowRunsAndStepNamesIndexed(
            $runs,
            array_map(
                static fn (\Fluxx\Workflow\WorkflowStepDefinition $step): string => $step->code(),
                $definition->steps(),
            ),
        );

        $items = array_map(
            function (WorkflowRun $run) use ($definition, $stepRunMap): WorkflowExecutionOverview {
                $steps = $this->buildExecutionSteps($definition, $run->runId(), $stepRunMap);

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
     * @param array<string, array{
     *     stepType: string,
     *     status: string,
     *     processedCount: int,
     *     successCount: int,
     *     errorCount: int,
     *     durationMs: ?int,
     *     memoryPeakBytes: ?int,
     *     idempotenceKey: ?string,
     *     deduplicationStatus: string,
     *     deduplicatedFromRunId: ?string,
     *     errorMessage: ?string,
     *     errorPayload: ?array<string, mixed>
     * }> $stepRunMap
     * @return list<WorkflowExecutionStepOverview>
     */
    private function buildExecutionSteps(WorkflowDefinition $definition, string $runId, array $stepRunMap): array
    {
        return array_map(
            fn (\Fluxx\Workflow\WorkflowStepDefinition $step): WorkflowExecutionStepOverview => $this->buildExecutionStepOverview(
                $step->type(),
                $step->code(),
                $step->name(),
                $stepRunMap[$runId . '::' . $step->code()] ?? null,
            ),
            $definition->steps(),
        );
    }

    /**
     * @param array{
     *     stepType: string,
     *     status: string,
     *     processedCount: int,
     *     successCount: int,
     *     errorCount: int,
     *     durationMs: ?int,
     *     memoryPeakBytes: ?int,
     *     idempotenceKey: ?string,
     *     deduplicationStatus: string,
     *     deduplicatedFromRunId: ?string,
     *     errorMessage: ?string,
     *     errorPayload: ?array<string, mixed>
     * }|null $stepRun
     */
    private function buildExecutionStepOverview(
        string $type,
        string $code,
        string $name,
        ?array $stepRun,
    ): WorkflowExecutionStepOverview {
        $stepType = $this->stepTypeRegistry->get($type);

        return new WorkflowExecutionStepOverview(
            type: $type,
            typeLabel: $stepType->label(),
            typeTone: $stepType->toneClass(),
            typeToneStyle: $stepType->toneStyle(),
            code: $code,
            name: $name,
            status: $stepRun['status'] ?? 'pending',
            processedCount: $stepRun['processedCount'] ?? 0,
            successCount: $stepRun['successCount'] ?? 0,
            errorCount: $stepRun['errorCount'] ?? 0,
            durationMs: $stepRun['durationMs'] ?? null,
            memoryPeakBytes: $stepRun['memoryPeakBytes'] ?? null,
            idempotenceKey: $stepRun['idempotenceKey'] ?? null,
            deduplicationStatus: $stepRun['deduplicationStatus'] ?? 'none',
            deduplicatedFromRunId: $stepRun['deduplicatedFromRunId'] ?? null,
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
        $bucketStats = $this->workflowRunRepository->aggregateCreatedSinceByWorkflowName(
            $definition->code(),
            $startAt,
            $range === 'year' ? 'month' : 'day',
        );
        $runStatistics = $this->workflowRunRepository->summarizeCreatedSinceByWorkflowName($definition->code(), $startAt);
        $stepStatistics = $this->workflowStepRunRepository->aggregateLatestStepStatisticsByWorkflowNameSince($definition->code(), $startAt);

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
            stepMetrics: $this->buildStepStatistics($definition, $stepStatistics['steps']),
            executionTotal: $executionTotal,
            errorTotal: $errorTotal,
            maxValue: $maxValue,
            yAxisTicks: $this->buildYAxisTicks($maxValue),
        );
    }

    /**
     * @return list<WorkflowStatisticsRangeView>
     */
    private function buildStatisticsRanges(string $range): array
    {
        return [
            new WorkflowStatisticsRangeView('week', 'workflow_show.range_week', $range === 'week'),
            new WorkflowStatisticsRangeView('month', 'workflow_show.range_month', $range === 'month'),
            new WorkflowStatisticsRangeView('year', 'workflow_show.range_year', $range === 'year'),
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
     *     recordErrorTotal: int,
     *     steps: array<string, array{
     *         durationTotal: int,
     *         durationCount: int,
     *         failureCount: int,
     *         retryCount: int,
     *         idempotenceHitCount: int,
     *         executionCount: int
     *     }>
     * } $stepStatistics
     * @return list<WorkflowStatisticsMetricView>
     */
    private function buildAdvancedStatisticsMetrics(array $runStatistics, array $stepStatistics): array
    {
        $runCount = $runStatistics['runCount'];

        return [
            new WorkflowStatisticsMetricView('workflow_show.metric_avg_duration', $this->formatDurationMetric($this->average($runStatistics['durations']))),
            new WorkflowStatisticsMetricView('workflow_show.metric_p95_duration', $this->formatDurationMetric($this->percentile95($runStatistics['durations']))),
            new WorkflowStatisticsMetricView('workflow_show.metric_failure_rate', $this->formatPercentageMetric($runStatistics['failedCount'], $runCount), $runStatistics['failedCount'] > 0 ? 'error' : 'default'),
            new WorkflowStatisticsMetricView('workflow_show.metric_partial_failure_rate', $this->formatPercentageMetric($runStatistics['partialFailedCount'], $runCount), $runStatistics['partialFailedCount'] > 0 ? 'warning' : 'default'),
            new WorkflowStatisticsMetricView('workflow_show.metric_retry_rate', $this->formatPercentageMetric($stepStatistics['retryRunCount'], $runCount), $stepStatistics['retryRunCount'] > 0 ? 'warning' : 'default'),
            new WorkflowStatisticsMetricView('workflow_show.metric_relaunch_rate', $this->formatPercentageMetric($runStatistics['relaunchCount'], $runCount)),
            new WorkflowStatisticsMetricView('workflow_show.metric_processed_total', (string) $stepStatistics['processedTotal']),
            new WorkflowStatisticsMetricView('workflow_show.metric_success_total', (string) $stepStatistics['successTotal']),
            new WorkflowStatisticsMetricView('workflow_show.metric_record_errors_total', (string) $stepStatistics['recordErrorTotal'], $stepStatistics['recordErrorTotal'] > 0 ? 'error' : 'default'),
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
    private function buildStepStatistics(WorkflowDefinition $definition, array $stepStatsByCode): array
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

        foreach ($stepStatsByCode as $stepCode => $stepStats) {
            if (!isset($stats[$stepCode])) {
                continue;
            }

            $stats[$stepCode] = [
                ...$stats[$stepCode],
                ...$stepStats,
            ];
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
