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
        $definitions = [];

        foreach ($this->registry->all() as $workflow) {
            $definitions[$workflow->definition()->code()] = $workflow->definition();
        }

        $issues = [];
        $seen = [];
        $failureCounts = [];
        $searchQuery = trim($searchQuery);
        $erroredStepRuns = $this->workflowStepRunRepository->findFailedByWorkflowNames(array_keys($definitions));

        foreach ($erroredStepRuns as $stepRun) {
            $workflowCode = $stepRun->workflowRun()->workflowName();
            $dedupKey = sprintf('%s|%s|%s', $workflowCode, $stepRun->workflowRun()->runId(), $stepRun->stepName());
            $failureCounts[$dedupKey] = ($failureCounts[$dedupKey] ?? 0) + 1;
        }

        foreach ($erroredStepRuns as $stepRun) {
            $definition = $definitions[$stepRun->workflowRun()->workflowName()] ?? null;

            if ($definition === null) {
                continue;
            }

            $stepCode = $stepRun->stepName();
            $stepName = $stepCode;
            $dedupKey = sprintf('%s|%s|%s', $definition->code(), $stepRun->workflowRun()->runId(), $stepCode);

            if (($seen[$dedupKey] ?? false) === true) {
                continue;
            }

            $seen[$dedupKey] = true;

            try {
                $stepName = $definition->step($stepCode)->name();
            } catch (\InvalidArgumentException) {
            }

            $issue = new TroubleshootingIssueView(
                workflowCode: $definition->code(),
                workflowName: $definition->name(),
                sourceSystem: $definition->sourceSystem(),
                targetSystem: $definition->targetSystem(),
                stepCode: $stepCode,
                stepName: $stepName,
                runId: $stepRun->workflowRun()->runId(),
                failedAt: $stepRun->finishedAt() ?? $stepRun->createdAt(),
                errorMessage: trim($stepRun->errorMessage() ?? '') !== '' ? (string) $stepRun->errorMessage() : 'No error message stored.',
                failureCount: $failureCounts[$dedupKey] ?? 1,
            );

            if ($searchQuery !== '' && !$this->matchesSearch($issue, $searchQuery)) {
                continue;
            }

            $issues[] = $issue;
        }

        usort($issues, static fn (TroubleshootingIssueView $left, TroubleshootingIssueView $right): int => [
            $right->failedAt()?->getTimestamp() ?? 0,
            $left->workflowName(),
            $left->stepName(),
        ] <=> [
            $left->failedAt()?->getTimestamp() ?? 0,
            $right->workflowName(),
            $right->stepName(),
        ]);

        return $issues;
    }

    public function paginate(int $page = 1, int $perPage = self::DEFAULT_PER_PAGE, string $searchQuery = ''): TroubleshootingCatalogPage
    {
        $page = max($page, 1);
        $perPage = max($perPage, 1);
        $items = $this->all($searchQuery);
        $totalItems = count($items);
        $totalPages = max((int) ceil($totalItems / $perPage), 1);
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        return new TroubleshootingCatalogPage(
            items: array_values(array_slice($items, $offset, $perPage)),
            currentPage: $page,
            perPage: $perPage,
            totalItems: $totalItems,
            totalPages: $totalPages,
        );
    }

    private function matchesSearch(TroubleshootingIssueView $issue, string $searchQuery): bool
    {
        $needle = mb_strtolower($searchQuery);

        foreach ([
            $issue->workflowName(),
            $issue->workflowCode(),
            $issue->sourceSystem(),
            $issue->targetSystem(),
            $issue->stepName(),
            $issue->stepCode(),
            $issue->runId(),
            $issue->errorMessage(),
        ] as $haystack) {
            if (str_contains(mb_strtolower($haystack), $needle)) {
                return true;
            }
        }

        return false;
    }
}
