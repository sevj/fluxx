<?php

declare(strict_types=1);

namespace Fluxx\Ui;

use Fluxx\Repository\WorkflowStepRunRepository;
use Fluxx\Workflow\SynchronizationRegistry;

final readonly class TroubleshootingCatalog
{
    private const DEFAULT_PER_PAGE = 10;

    public function __construct(
        private SynchronizationRegistry $registry,
        private WorkflowStepRunRepository $workflowStepRunRepository,
    ) {
    }

    /**
     * @return list<TroubleshootingIssueView>
     */
    public function all(string $searchQuery = ''): array
    {
        $definitions = $this->definitionsByCode();
        $workflowCodes = array_keys($definitions);
        $searchFilters = $this->buildSearchFilters($definitions, $searchQuery);
        $totalItems = $this->workflowStepRunRepository->countTroubleshootingIssuesByWorkflowNames(
            $workflowCodes,
            $searchQuery,
            $searchFilters['workflowCodes'],
            $searchFilters['stepCodesByWorkflowCode'],
        );

        if ($totalItems === 0) {
            return [];
        }

        return $this->buildIssuesFromRows(
            $this->workflowStepRunRepository->findTroubleshootingIssueRowsByWorkflowNames(
                $workflowCodes,
                $totalItems,
                0,
                $searchQuery,
                $searchFilters['workflowCodes'],
                $searchFilters['stepCodesByWorkflowCode'],
            ),
            $definitions,
        );
    }

    public function paginate(int $page = 1, int $perPage = self::DEFAULT_PER_PAGE, string $searchQuery = ''): TroubleshootingCatalogPage
    {
        $page = max($page, 1);
        $perPage = max($perPage, 1);
        $definitions = $this->definitionsByCode();
        $workflowCodes = array_keys($definitions);
        $searchFilters = $this->buildSearchFilters($definitions, $searchQuery);
        $totalItems = $this->workflowStepRunRepository->countTroubleshootingIssuesByWorkflowNames(
            $workflowCodes,
            $searchQuery,
            $searchFilters['workflowCodes'],
            $searchFilters['stepCodesByWorkflowCode'],
        );
        $totalPages = max((int) ceil($totalItems / $perPage), 1);
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;
        $items = $totalItems > 0
            ? $this->buildIssuesFromRows(
                $this->workflowStepRunRepository->findTroubleshootingIssueRowsByWorkflowNames(
                    $workflowCodes,
                    $perPage,
                    $offset,
                    $searchQuery,
                    $searchFilters['workflowCodes'],
                    $searchFilters['stepCodesByWorkflowCode'],
                ),
                $definitions,
            )
            : [];

        return new TroubleshootingCatalogPage(
            items: $items,
            currentPage: $page,
            perPage: $perPage,
            totalItems: $totalItems,
            totalPages: $totalPages,
        );
    }

    /**
     * @return array<string, \Fluxx\Workflow\WorkflowDefinition>
     */
    private function definitionsByCode(): array
    {
        $definitions = [];

        foreach ($this->registry->all() as $workflow) {
            $definitions[$workflow->definition()->code()] = $workflow->definition();
        }

        return $definitions;
    }

    /**
     * @param array<string, \Fluxx\Workflow\WorkflowDefinition> $definitions
     * @return array{workflowCodes: list<string>, stepCodesByWorkflowCode: array<string, list<string>>}
     */
    private function buildSearchFilters(array $definitions, string $searchQuery): array
    {
        $searchQuery = trim($searchQuery);

        if ($searchQuery === '') {
            return [
                'workflowCodes' => [],
                'stepCodesByWorkflowCode' => [],
            ];
        }

        $needle = mb_strtolower($searchQuery);
        $matchedWorkflowCodes = [];
        $matchedStepCodesByWorkflowCode = [];

        foreach ($definitions as $workflowCode => $definition) {
            foreach ([
                $definition->code(),
                $definition->name(),
                $definition->sourceSystem(),
                $definition->targetSystem(),
            ] as $workflowField) {
                if (str_contains(mb_strtolower($workflowField), $needle)) {
                    $matchedWorkflowCodes[] = $workflowCode;
                    break;
                }
            }

            foreach ($definition->steps() as $step) {
                if (
                    str_contains(mb_strtolower($step->code()), $needle)
                    || str_contains(mb_strtolower($step->name()), $needle)
                ) {
                    $matchedStepCodesByWorkflowCode[$workflowCode][] = $step->code();
                }
            }
        }

        return [
            'workflowCodes' => array_values(array_unique($matchedWorkflowCodes)),
            'stepCodesByWorkflowCode' => array_map(
                static fn (array $stepCodes): array => array_values(array_unique($stepCodes)),
                $matchedStepCodesByWorkflowCode,
            ),
        ];
    }

    /**
     * @param list<array{
     *     workflowCode: string,
     *     sourceSystem: string,
     *     targetSystem: string,
     *     runId: string,
     *     stepCode: string,
     *     failedAt: ?\DateTimeImmutable,
     *     errorMessage: string,
     *     failureCount: int
     * }> $rows
     * @param array<string, \Fluxx\Workflow\WorkflowDefinition> $definitions
     * @return list<TroubleshootingIssueView>
     */
    private function buildIssuesFromRows(array $rows, array $definitions): array
    {
        $issues = [];

        foreach ($rows as $row) {
            $definition = $definitions[$row['workflowCode']] ?? null;

            if ($definition === null) {
                continue;
            }

            $stepName = $row['stepCode'];

            try {
                $stepName = $definition->step($row['stepCode'])->name();
            } catch (\InvalidArgumentException) {
            }

            $issues[] = new TroubleshootingIssueView(
                workflowCode: $definition->code(),
                workflowName: $definition->name(),
                sourceSystem: $row['sourceSystem'],
                targetSystem: $row['targetSystem'],
                stepCode: $row['stepCode'],
                stepName: $stepName,
                runId: $row['runId'],
                failedAt: $row['failedAt'],
                errorMessage: $row['errorMessage'],
                failureCount: $row['failureCount'],
            );
        }

        return $issues;
    }
}
