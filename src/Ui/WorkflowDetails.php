<?php

declare(strict_types=1);

namespace Fluxx\Ui;

use DateInterval;
use DateTimeImmutable;
use Fluxx\Reporting\WorkflowStepRunStatistics;
use Fluxx\Repository\WorkflowRunRepository;
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
        private WorkflowStepRunStatistics $workflowStepRunStatistics,
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
            description: $definition->description(),
            category: $definition->category(),
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
            description: $definition->description(),
            category: $definition->category(),
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
        $laneCoverage = $this->computeStepLaneCoverage($steps, $stepMap, $lanePaths, $branchPaths);
        $laneCoverage = $this->resolveFanInLaneCollisions($steps, $laneCoverage);
        $maxLane = $this->computeMaxLane($laneCoverage);
        [$laneCoverage, $rowCount] = $this->resolveLaneSpans($steps, $stepMap, $laneCoverage, $maxLane);
        [$laneCoverage, $rowCount] = $this->compactLaneCoverage($laneCoverage);
        $graphRows = [];

        ksort($rows);

        $rowLayouts = array_map(fn (array $row): string => $this->resolveRowLayout($row), $rows);
        $rowIndex = 0;

        foreach ($rows as $row) {
            $nodes = array_map(
                fn (WorkflowStepDefinitionView $step): WorkflowGraphNodeView => $this->createGraphNode($step, $laneCoverage),
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
        $pending = [];

        foreach ($steps as $step) {
            foreach ($step->dependsOn() as $dependencyCode) {
                if (!isset($nodeMap[$dependencyCode], $nodeMap[$step->code()])) {
                    continue;
                }

                $pending[] = [$nodeMap[$dependencyCode], $nodeMap[$step->code()]];
            }
        }

        $targetYs = $this->distributeFanInTargetYs($pending);
        $edges = [];

        foreach ($pending as $index => [$from, $to]) {
            $edges[] = new WorkflowGraphEdgeView(
                from: $from,
                to: $to,
                targetY: $targetYs[$index] ?? null,
            );
        }

        return $edges;
    }

    /**
     * Merge cards render as a single centered card, so incoming fan-in edges
     * must converge onto that card instead of stopping at each source lane.
     * Entry points are spread across the card's height and ordered by source
     * position so edges arriving from above connect above lower ones.
     *
     * @param list<array{0: WorkflowGraphNodeView, 1: WorkflowGraphNodeView}> $pending
     * @return array<int, float>
     */
    private function distributeFanInTargetYs(array $pending): array
    {
        $groups = [];
        $targetYs = [];

        foreach ($pending as $index => [, $to]) {
            if (count($to->step()->dependsOn()) < 2) {
                continue;
            }

            $groups[$to->step()->code()][] = $index;
        }

        foreach ($groups as $indexes) {
            usort(
                $indexes,
                fn (int $left, int $right): int => WorkflowGraphEdgeView::laneCenter($pending[$left][0])
                    <=> WorkflowGraphEdgeView::laneCenter($pending[$right][0]),
            );

            $count = count($indexes);
            $centerY = WorkflowGraphEdgeView::laneCenter($pending[$indexes[0]][1]);
            $cardTop = $centerY - WorkflowGraphEdgeView::CARD_HEIGHT / 2 + WorkflowGraphEdgeView::CARD_END_INSET;
            $cardBottom = $centerY + WorkflowGraphEdgeView::CARD_HEIGHT / 2 - WorkflowGraphEdgeView::CARD_END_INSET;

            foreach ($indexes as $slot => $index) {
                $targetYs[$index] = $cardTop + ($cardBottom - $cardTop) * ($count > 1 ? $slot / ($count - 1) : 0.5);
            }
        }

        return $targetYs;
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

        usort($terminalPaths, [$this, 'comparePaths']);

        return $terminalPaths;
    }

    /**
     * Lexicographic branch-path comparison following the definition's reading
     * order: element-wise from the left, a prefix sorts before its extensions
     * so sibling branches keep their declared order across levels.
     *
     * @param list<int> $left
     * @param list<int> $right
     */
    private function comparePaths(array $left, array $right): int
    {
        $length = min(count($left), count($right));

        for ($index = 0; $index < $length; ++$index) {
            if ($left[$index] !== $right[$index]) {
                return $left[$index] <=> $right[$index];
            }
        }

        return count($left) <=> count($right);
    }

    /**
     * @param list<WorkflowStepDefinitionView> $steps
     * @param array<string, WorkflowStepDefinitionView> $stepMap
     * @param list<list<int>> $lanePaths
     * @param array<string, list<int>> $branchPaths
     * @return array<string, list<int>>
     */
    private function computeStepLaneCoverage(array $steps, array $stepMap, array $lanePaths, array $branchPaths): array
    {
        $coverage = [];

        foreach ($steps as $step) {
            $this->resolveStepLaneCoverage($step->code(), $stepMap, $lanePaths, $branchPaths, $coverage);
        }

        return $coverage;
    }

    /**
     * @param array<string, WorkflowStepDefinitionView> $stepMap
     * @param list<list<int>> $lanePaths
     * @param array<string, list<int>> $branchPaths
     * @param array<string, list<int>> $coverage
     * @return list<int>
     */
    private function resolveStepLaneCoverage(
        string $code,
        array $stepMap,
        array $lanePaths,
        array $branchPaths,
        array &$coverage,
    ): array {
        if (isset($coverage[$code])) {
            return $coverage[$code];
        }

        $step = $stepMap[$code];

        if ($step->dependsOn() === []) {
            $all = $lanePaths === [] ? [1] : range(1, count($lanePaths));

            return $coverage[$code] = $all;
        }

        if (count($step->dependsOn()) === 1) {
            $parentCode = $step->dependsOn()[0];
            $parentCoverage = $this->resolveStepLaneCoverage($parentCode, $stepMap, $lanePaths, $branchPaths, $coverage);

            $siblings = array_values(array_filter(
                array_values($stepMap),
                static fn (WorkflowStepDefinitionView $candidate): bool => $candidate->dependsOn() === [$parentCode],
            ));

            if (count($siblings) <= 1) {
                return $coverage[$code] = $parentCoverage;
            }

            $childPath = $branchPaths[$code] ?? [];
            $covered = [];

            foreach ($lanePaths as $index => $lanePath) {
                if ($this->isPathPrefix($childPath, $lanePath)) {
                    $covered[] = $index + 1;
                }
            }

            if ($covered === []) {
                $covered[] = 1;
            }

            return $coverage[$code] = $covered;
        }

        // A multi-dependency step (branch join, shared "users" dependency...)
        // must not inherit the union of its parents' lanes: joining one broad
        // root-covering dependency with a branch-local one would blow the step
        // up to a full-graph spanner and force the collision resolver to
        // scatter whole columns. The step keeps the narrowest dependency
        // window (its branch lane); fan-in convergence is restored afterwards
        // by the merge widening in resolveLaneSpans().
        $primary = null;
        $primarySize = 0;

        foreach ($step->dependsOn() as $dependencyCode) {
            $parentCoverage = $this->resolveStepLaneCoverage($dependencyCode, $stepMap, $lanePaths, $branchPaths, $coverage);
            $size = max($parentCoverage) - min($parentCoverage) + 1;

            if ($primary === null || $size < $primarySize) {
                $primary = $parentCoverage;
                $primarySize = $size;
            }
        }

        return $coverage[$code] = $primary;
    }

    /**
     * Resolves lane conflicts inside each column without dismantling the
     * branch structure: multi-lane spanners keep their (possibly shifted)
     * lane window so parent boxes stay centered over their children, and
     * single-lane steps move to the nearest free lane only when theirs is
     * taken. Demotion to a fresh lane is the last resort.
     *
     * @param list<WorkflowStepDefinitionView> $steps
     * @param array<string, list<int>> $laneCoverage
     * @return array<string, list<int>>
     */
    private function resolveFanInLaneCollisions(array $steps, array $laneCoverage): array
    {
        $byColumn = [];

        foreach ($steps as $step) {
            $byColumn[$step->level()][] = $step;
        }

        ksort($byColumn);

        foreach ($byColumn as $columnSteps) {
            if (count($columnSteps) < 2) {
                continue;
            }

            $spanners = [];
            $singles = [];

            foreach ($columnSteps as $step) {
                $lanes = $laneCoverage[$step->code()] ?? [1];

                if (count($lanes) > 1) {
                    $spanners[] = $step;
                } else {
                    $singles[] = $step;
                }
            }

            if ($spanners === [] && $singles === []) {
                continue;
            }

            usort(
                $spanners,
                static function (WorkflowStepDefinitionView $a, WorkflowStepDefinitionView $b) use ($laneCoverage): int {
                    $lanesA = $laneCoverage[$a->code()] ?? [1];
                    $lanesB = $laneCoverage[$b->code()] ?? [1];

                    return [count($lanesB), min($lanesA), $a->code()] <=> [count($lanesA), min($lanesB), $b->code()];
                },
            );

            usort(
                $singles,
                static function (WorkflowStepDefinitionView $a, WorkflowStepDefinitionView $b) use ($laneCoverage): int {
                    $laneA = ($laneCoverage[$a->code()] ?? [1])[0] ?? 1;
                    $laneB = ($laneCoverage[$b->code()] ?? [1])[0] ?? 1;

                    return [$laneA, $a->code()] <=> [$laneB, $b->code()];
                },
            );

            $spannerWindows = [];
            $singleClaims = [];

            foreach ($spanners as $step) {
                $lanes = $laneCoverage[$step->code()] ?? [1];
                $windowStart = min($lanes);
                $windowEnd = max($lanes);
                $size = $windowEnd - $windowStart + 1;

                if (!$this->windowConflicts($windowStart, $windowEnd, $spannerWindows, $singleClaims)) {
                    $spannerWindows[] = [$windowStart, $windowEnd];
                    continue;
                }

                $shifted = $this->findFreeWindow($windowStart, $size, $spannerWindows, $singleClaims);

                if ($shifted !== null) {
                    $spannerWindows[] = $shifted;
                    $laneCoverage[$step->code()] = range($shifted[0], $shifted[1]);

                    continue;
                }

                $lane = $this->nearestFreeLane(range($windowStart, $windowEnd), $spannerWindows, $singleClaims)
                    ?? ($this->highestClaim($spannerWindows, $singleClaims) + 1);
                $spannerWindows[] = [$lane, $lane];
                $laneCoverage[$step->code()] = [$lane];
            }

            foreach ($singles as $step) {
                $lane = ($laneCoverage[$step->code()] ?? [1])[0] ?? 1;

                if (!$this->laneClaimed($lane, $spannerWindows, $singleClaims)) {
                    $singleClaims[$lane] = $step->code();

                    continue;
                }

                $moved = $this->nearestFreeLane([$lane], $spannerWindows, $singleClaims)
                    ?? ($this->highestClaim($spannerWindows, $singleClaims) + 1);
                $singleClaims[$moved] = $step->code();
                $laneCoverage[$step->code()] = [$moved];
            }
        }

        return $laneCoverage;
    }

    /**
     * @param array<int, array{0: int, 1: int}> $spannerWindows
     * @param array<int, string> $singleClaims
     */
    private function windowConflicts(int $start, int $end, array $spannerWindows, array $singleClaims): bool
    {
        foreach ($spannerWindows as [$otherStart, $otherEnd]) {
            if ($start <= $otherEnd && $otherStart <= $end) {
                return true;
            }
        }

        foreach (array_keys($singleClaims) as $lane) {
            if ($lane >= $start && $lane <= $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * Searches for the nearest fully free window of the requested size,
     * probing outward from the original position so a spanner keeps its
     * neighbours when it has to move.
     *
     * @param array<int, array{0: int, 1: int}> $spannerWindows
     * @param array<int, string> $singleClaims
     * @return array{0: int, 1: int}|null
     */
    private function findFreeWindow(int $preferredStart, int $size, array $spannerWindows, array $singleClaims): ?array
    {
        for ($offset = 0; $offset <= 32; ++$offset) {
            foreach ([$preferredStart - $offset, $preferredStart + $offset] as $start) {
                if ($start < 1) {
                    continue;
                }

                if (!$this->windowConflicts($start, $start + $size - 1, $spannerWindows, $singleClaims)) {
                    return [$start, $start + $size - 1];
                }
            }
        }

        return null;
    }

    /**
     * @param list<int> $preferences
     * @param array<int, array{0: int, 1: int}> $spannerWindows
     * @param array<int, string> $singleClaims
     */
    private function nearestFreeLane(array $preferences, array $spannerWindows, array $singleClaims): ?int
    {
        for ($offset = 0; $offset <= 32; ++$offset) {
            foreach ($preferences as $lane) {
                foreach ([$lane - $offset, $lane + $offset] as $candidate) {
                    if ($candidate < 1) {
                        continue;
                    }

                    if (!$this->laneClaimed($candidate, $spannerWindows, $singleClaims)) {
                        return $candidate;
                    }
                }
            }
        }

        return null;
    }

    private function laneClaimed(int $lane, array $spannerWindows, array $singleClaims): bool
    {
        foreach ($spannerWindows as [$start, $end]) {
            if ($lane >= $start && $lane <= $end) {
                return true;
            }
        }

        return isset($singleClaims[$lane]);
    }

    /**
     * @param array<int, array{0: int, 1: int}> $spannerWindows
     * @param array<int, string> $singleClaims
     */
    private function highestClaim(array $spannerWindows, array $singleClaims): int
    {
        $highest = 0;

        foreach ($spannerWindows as [, $end]) {
            $highest = max($highest, $end);
        }

        foreach (array_keys($singleClaims) as $lane) {
            $highest = max($highest, $lane);
        }

        return $highest;
    }

    /**
     * Collisions are resolved by shifting and demoting steps to free lanes,
     * which can leave fully unoccupied lanes between occupied ones: the graph
     * would render those as dead vertical bands. Renumbering every lane while
     * preserving the relative order (and therefore the contiguous windows of
     * multi-lane spans) collapses the gaps without touching the topology.
     *
     * @param array<string, list<int>> $laneCoverage
     * @return array{0: array<string, list<int>>, 1: int}
     */
    private function compactLaneCoverage(array $laneCoverage): array
    {
        $used = [];

        foreach ($laneCoverage as $lanes) {
            foreach ($lanes as $lane) {
                $used[$lane] = true;
            }
        }

        if ($used === []) {
            return [$laneCoverage, 1];
        }

        $used = array_keys($used);
        sort($used);
        $remapping = array_flip($used);
        $compacted = [];

        foreach ($laneCoverage as $code => $lanes) {
            $remapped = array_map(static fn (int $lane): int => $remapping[$lane] + 1, $lanes);
            sort($remapped);
            $compacted[$code] = $remapped;
        }

        return [$compacted, count($used)];
    }

    /**
     * Computes the maximum lane index across all lane coverages after
     * collision resolution, determining the total row count for the graph.
     *
     * @param array<string, list<int>> $laneCoverage
     */
    private function computeMaxLane(array $laneCoverage): int
    {
        $maxLane = 1;

        foreach ($laneCoverage as $lanes) {
            if ($lanes !== []) {
                $maxLane = max($maxLane, max($lanes));
            }
        }

        return $maxLane;
    }

    /**
     * After collision resolution and centering, multi-lane spanners may have
     * been assigned to lanes beyond the original lane-path count. This method:
     *
     *   1. Widens root steps so they span all occupied lanes.
     *   2. Widens merge steps (alone in their column) so they span the union
     *      of their parents' post-centering coverages.
     *   3. Propagates corrected multi-lane spans to single-dependency
     *      only-child descendants, so joins are visually centered correctly.
     *
     * Steps modified by collision resolution keep their single-lane assignment.
     *
     * @param list<WorkflowStepDefinitionView> $steps
     * @param array<string, WorkflowStepDefinitionView> $stepMap
     * @param array<string, list<int>> $laneCoverage
     * @return array{0: array<string, list<int>>, 1: int}
     */
    private function resolveLaneSpans(array $steps, array $stepMap, array $laneCoverage, int $maxLane): array
    {
        $byLevel = [];

        foreach ($steps as $step) {
            $byLevel[$step->level()][] = $step;
        }

        ksort($byLevel);

        foreach ($byLevel as $levelSteps) {
            foreach ($levelSteps as $step) {
                if ($step->dependsOn() === []) {
                    $laneCoverage[$step->code()] = range(1, $maxLane);
                } elseif (count($step->dependsOn()) > 1) {
                    if (count($levelSteps) === 1) {
                        $union = [];

                        foreach ($step->dependsOn() as $dependencyCode) {
                            foreach ($laneCoverage[$dependencyCode] ?? [1] as $lane) {
                                if (!in_array($lane, $union, true)) {
                                    $union[] = $lane;
                                }
                            }
                        }

                        sort($union);
                        $laneCoverage[$step->code()] = $union;
                    }
                } else {
                    $parentCode = $step->dependsOn()[0];
                    $parentLanes = $laneCoverage[$parentCode] ?? [1];

                    if (count($parentLanes) > 1) {
                        $siblings = array_values(array_filter(
                            array_values($stepMap),
                            static fn (WorkflowStepDefinitionView $candidate): bool => $candidate->dependsOn() === [$parentCode],
                        ));

                        if (count($siblings) <= 1) {
                            $laneCoverage[$step->code()] = $parentLanes;
                        }
                    }
                }
            }
        }

        return [$laneCoverage, $this->computeMaxLane($laneCoverage)];
    }

    /**
     * @param array<string, list<int>> $laneCoverage
     */
    private function createGraphNode(
        WorkflowStepDefinitionView $step,
        array $laneCoverage,
    ): WorkflowGraphNodeView {
        $coveredRows = $laneCoverage[$step->code()] ?? [1];

        if ($coveredRows === []) {
            $coveredRows = [1];
        }

        $rowStart = min($coveredRows);
        $rowSpan = count($coveredRows) > 1 ? (max($coveredRows) - min($coveredRows) + 1) : 1;

        return new WorkflowGraphNodeView(
            step: $step,
            rowStart: $rowStart,
            columnStart: $step->level() + 1,
            rowSpan: $rowSpan,
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
        $stepRunMap = $this->workflowStepRunStatistics->findLatestByWorkflowRunsAndStepNamesIndexed(
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
                    synchronous: $run->isSynchronous(),
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
        $stepStatistics = $this->workflowStepRunStatistics->aggregateLatestStepStatisticsByWorkflowNameSince($definition->code(), $startAt);

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
