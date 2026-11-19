<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Report;

use DateTimeImmutable;
use DateTimeInterface;
use Fluxx\Entity\Enum\WorkflowRunStatus;
use Fluxx\Entity\Enum\WorkflowStepRunStatus;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;
use Fluxx\Repository\WorkflowStepRunLookupInterface;
use Fluxx\Workflow\Check\CheckReport;
use Fluxx\Workflow\SynchronizationRegistry;
use Fluxx\Workflow\WorkflowDefinition;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/**
 * Builds the stored report of a completed run from its step runs. Content it cannot read is reported
 * as such instead of failing the build.
 */
final readonly class RunReportBuilder
{
    public const FORMAT_VERSION = 1;

    public function __construct(
        private SynchronizationRegistry $registry,
        private WorkflowStepRunLookupInterface $stepRunLookup,
        #[AutowireLocator('fluxx.run_report_section')]
        private ContainerInterface $sections,
    ) {
    }

    /**
     * Whether a report can be built for the run: completed, or pruned after all its steps ended
     * without failure or cancellation.
     */
    public function isReportable(WorkflowRun $run): bool
    {
        return self::isReportableWith($run, $this->stepRunLookup->findByWorkflowRunOrdered($run));
    }

    /**
     * Same rule as isReportable() for a caller that already loaded the step runs of the run, in
     * execution order.
     *
     * @param iterable<WorkflowStepRun> $stepRuns
     */
    public static function isReportableWith(WorkflowRun $run, iterable $stepRuns): bool
    {
        if ($run->status()->isCompleted()) {
            return true;
        }

        if ($run->status() !== WorkflowRunStatus::PayloadsPruned) {
            return false;
        }

        $latest = self::latestByCode($stepRuns);

        foreach ($latest as $stepRun) {
            if (in_array($stepRun->status(), [WorkflowStepRunStatus::Failed, WorkflowStepRunStatus::Cancelled], true)) {
                return false;
            }
        }

        return $latest !== [];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RunNotReportableException when the run did not complete
     */
    public function build(WorkflowRun $run): array
    {
        $stepRuns = $this->latestStepRunsByCode($run);
        $status = $this->reportedStatus($run, $stepRuns);
        $definition = $this->registry->has($run->workflowName()) ? $this->registry->get($run->workflowName())->definition() : null;
        $criticalPath = $definition !== null ? CriticalPath::compute($definition, $this->stepRunsOfThisRun($run, $stepRuns)) : [];

        $objects = [];
        $anomalies = [];
        $checks = [];
        $unreadable = [];
        $steps = [];

        foreach ($stepRuns as $code => $stepRun) {
            $source = $this->collectStepContent($stepRun, $objects, $anomalies, $checks, $unreadable);
            $steps[] = $this->describeStep($definition, $stepRun, $source, in_array($code, $criticalPath, true));
        }

        $links = array_values(array_map($this->finalizeLink(...), array_filter($objects, static fn (array $object): bool => $object['link'])));
        $objects = array_values(array_map($this->finalizeObject(...), array_filter($objects, static fn (array $object): bool => !$object['link'])));

        return [
            'format_version' => self::FORMAT_VERSION,
            'summary' => $this->summarize($run, $definition, $status, $steps, $objects, $links, $anomalies, $checks, $criticalPath, $stepRuns),
            'objects' => $objects,
            'links' => $links,
            'steps' => $steps,
            'critical_path' => $criticalPath,
            'anomalies' => $anomalies,
            'checks' => $checks,
            'sections' => $this->buildSections($run, $definition),
            'unreadable_step_reports' => $unreadable,
        ];
    }

    /**
     * Pruning payloads replaces the run status, so a pruned run is accepted when none of its steps
     * failed or was cancelled, and its warning counter tells which completed status it had.
     *
     * @param array<string, WorkflowStepRun> $stepRuns
     */
    private function reportedStatus(WorkflowRun $run, array $stepRuns): WorkflowRunStatus
    {
        if (!self::isReportableWith($run, $stepRuns)) {
            throw new RunNotReportableException($run->status() === WorkflowRunStatus::PayloadsPruned
                ? sprintf('Run "%s" did not complete (a step failed or was cancelled before its payloads were pruned): a report is only built for a completed run.', $run->runId())
                : sprintf('Run "%s" has the status "%s": a report is only built for a completed run.', $run->runId(), $run->status()->value));
        }

        if ($run->status()->isCompleted()) {
            return $run->status();
        }

        return $run->warningCount() > 0 ? WorkflowRunStatus::CompletedWithWarnings : WorkflowRunStatus::Completed;
    }

    /**
     * A relaunched run keeps the step runs it preserved with their original times: they did not take
     * part in this run's duration.
     *
     * @param array<string, WorkflowStepRun> $stepRuns
     * @return array<string, WorkflowStepRun>
     */
    private function stepRunsOfThisRun(WorkflowRun $run, array $stepRuns): array
    {
        $runStart = $run->startedAt();

        return array_filter(
            $stepRuns,
            fn (WorkflowStepRun $stepRun): bool => $this->relaunchStatus($stepRun) !== 'preserved'
                && ($runStart === null || $stepRun->finishedAt() === null || $stepRun->finishedAt() >= $runStart),
        );
    }

    /**
     * @return string|null "preserved" or "reset" for a step run of a relaunched run
     */
    private function relaunchStatus(WorkflowStepRun $stepRun): ?string
    {
        $status = $stepRun->metadata()['relaunch']['status'] ?? null;

        return is_string($status) ? $status : null;
    }

    /**
     * @return array<string, WorkflowStepRun>
     */
    private function latestStepRunsByCode(WorkflowRun $run): array
    {
        return self::latestByCode($this->stepRunLookup->findByWorkflowRunOrdered($run));
    }

    /**
     * @param iterable<WorkflowStepRun> $stepRuns
     * @return array<string, WorkflowStepRun>
     */
    private static function latestByCode(iterable $stepRuns): array
    {
        $latest = [];

        foreach ($stepRuns as $stepRun) {
            $latest[$stepRun->stepName()] = $stepRun;
        }

        return $latest;
    }

    /**
     * @param array<string, array<string, mixed>> $objects
     * @param list<array<string, mixed>> $anomalies
     * @param list<array<string, mixed>> $checks
     * @param list<array{step: string, message: string}> $unreadable
     * @return string where the step content came from: step, check, metadata, none or unreadable
     */
    private function collectStepContent(WorkflowStepRun $stepRun, array &$objects, array &$anomalies, array &$checks, array &$unreadable): string
    {
        $code = $stepRun->stepName();
        $stored = $stepRun->report();

        try {
            $checkReport = CheckReport::fromStored($stored);
            $stepReport = $checkReport === null ? StepReport::fromStored($stored) : null;

            if ($stored !== null && $checkReport === null && $stepReport === null) {
                throw new \InvalidArgumentException(sprintf('Unknown report kind "%s".', is_string($stored['kind'] ?? null) ? $stored['kind'] : get_debug_type($stored['kind'] ?? null)));
            }
        } catch (\InvalidArgumentException|\TypeError $exception) {
            $unreadable[] = ['step' => $code, 'message' => $exception->getMessage()];

            return 'unreadable';
        }

        if ($checkReport !== null) {
            foreach ($checkReport->results() as $result) {
                $checks[] = ['step' => $code] + $result->toArray();
            }

            return 'check';
        }

        if ($stepReport !== null) {
            foreach ($stepReport->entries() as $entry) {
                $this->addObjects($objects, $code, $entry);
            }

            foreach ($stepReport->anomalies() as $anomaly) {
                $lines = array_filter($anomaly->lines, static fn (mixed $value): bool => is_int($value) || is_string($value));
                uasort($lines, self::compareAnomalyLines(...));
                $anomalies[] = [
                    'step' => $code,
                    'object' => $stepReport->entries()[0]->label,
                    'title' => $anomaly->title,
                    'severity' => $anomaly->severity->value,
                    'lines' => $lines,
                    'line_total' => count($lines),
                ];
            }

            return 'step';
        }

        $fallback = MetadataReportFallback::read($code, $stepRun->metadata());

        if ($fallback === null) {
            return 'none';
        }

        $this->addObjects($objects, $code, $fallback);

        return 'metadata';
    }

    /**
     * @param array<string, array<string, mixed>> $objects
     */
    private function addObjects(array &$objects, string $stepCode, ObjectStats $stats): void
    {
        $key = ($stats->link ? 'link:' : 'object:') . $stats->label;
        $object = $objects[$key] ?? [
            'label' => $stats->label,
            'link' => $stats->link,
            'removed' => 0,
            'read' => 0,
            'created' => 0,
            'updated' => 0,
            'excluded' => [],
            'rejected' => [],
            'rejected_total' => 0,
            'steps' => [],
        ];

        $object['read'] += $stats->read;
        $object['created'] += $stats->created;
        $object['updated'] += $stats->updated;
        $object['removed'] += $stats->removed;

        foreach ($stats->excluded as $reason => $count) {
            if (is_int($count)) {
                $object['excluded'][(string) $reason] = ($object['excluded'][(string) $reason] ?? 0) + $count;
            }
        }

        foreach ($stats->rejected as $record) {
            if (!is_array($record) || !is_string($record['message'] ?? null) || !(is_string($record['key'] ?? null) || is_int($record['key'] ?? null))) {
                continue;
            }

            ++$object['rejected_total'];
            $object['rejected'][] = ['key' => (string) $record['key'], 'message' => $record['message'], 'step' => $stepCode];
        }

        if (!in_array($stepCode, $object['steps'], true)) {
            $object['steps'][] = $stepCode;
        }

        $objects[$key] = $object;
    }

    /**
     * @param array<string, mixed> $object
     * @return array<string, mixed>
     */
    private function finalizeObject(array $object): array
    {
        return [
            'label' => $object['label'],
            'read' => $object['read'],
            'created' => $object['created'],
            'updated' => $object['updated'],
            'written' => $object['created'] + $object['updated'],
            'excluded' => $object['excluded'],
            'excluded_total' => array_sum($object['excluded']),
            'rejected' => $object['rejected'],
            'rejected_total' => $object['rejected_total'],
            'steps' => $object['steps'],
        ];
    }

    /**
     * @param array<string, mixed> $link
     * @return array<string, mixed>
     */
    private function finalizeLink(array $link): array
    {
        return [
            'label' => $link['label'],
            'read' => $link['read'],
            'written' => $link['created'],
            'removed' => $link['removed'],
            'excluded' => $link['excluded'],
            'excluded_total' => array_sum($link['excluded']),
            'rejected' => $link['rejected'],
            'rejected_total' => $link['rejected_total'],
            'steps' => $link['steps'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describeStep(?WorkflowDefinition $definition, WorkflowStepRun $stepRun, string $source, bool $critical): array
    {
        $durationMs = $this->durationMs($stepRun);

        return [
            'code' => $stepRun->stepName(),
            'name' => $this->stepName($definition, $stepRun->stepName()),
            'type' => $stepRun->stepType(),
            'status' => $stepRun->status()->value,
            'started_at' => $stepRun->startedAt()?->format(DateTimeInterface::ATOM),
            'finished_at' => $stepRun->finishedAt()?->format(DateTimeInterface::ATOM),
            'duration_ms' => $durationMs,
            'processed_count' => $stepRun->processedCount(),
            'success_count' => $stepRun->successCount(),
            'error_count' => $stepRun->errorCount(),
            'memory_peak_bytes' => $stepRun->memoryPeakBytes(),
            'throughput_per_second' => $durationMs !== null && $durationMs > 0
                ? round($stepRun->processedCount() / ($durationMs / 1000), 2)
                : null,
            'report' => $source,
            'critical' => $critical,
            'relaunch' => $this->relaunchStatus($stepRun),
        ];
    }

    /**
     * @param list<array<string, mixed>> $steps
     * @param list<array<string, mixed>> $objects
     * @param list<array<string, mixed>> $links
     * @param list<array<string, mixed>> $anomalies
     * @param list<array<string, mixed>> $checks
     * @param list<string> $criticalPath
     * @param array<string, WorkflowStepRun> $stepRuns
     * @return array<string, mixed>
     */
    private function summarize(
        WorkflowRun $run,
        ?WorkflowDefinition $definition,
        WorkflowRunStatus $status,
        array $steps,
        array $objects,
        array $links,
        array $anomalies,
        array $checks,
        array $criticalPath,
        array $stepRuns,
    ): array {
        $sum = static fn (string $key): int => array_sum(array_column($objects, $key));
        $sumLinks = static fn (string $key): int => array_sum(array_column($links, $key));
        $countWhere = static fn (array $rows, string $key, string $value): int => count(array_filter($rows, static fn (array $row): bool => $row[$key] === $value));
        $deviations = array_values(array_filter($checks, static fn (array $check): bool => $check['outcome'] === 'deviation'));

        return [
            'run_id' => $run->runId(),
            'workflow_code' => $run->workflowName(),
            'workflow_name' => $definition?->name() ?? $run->workflowName(),
            'trigger' => $run->trigger(),
            'status' => $status->value,
            'started_at' => $run->startedAt()?->format(DateTimeInterface::ATOM),
            'finished_at' => $run->finishedAt()?->format(DateTimeInterface::ATOM),
            'duration_ms' => $this->between($run->startedAt(), $run->finishedAt()),
            'warning_count' => $run->warningCount(),
            'step_count' => count($steps),
            'objects' => [
                'read' => $sum('read'),
                'created' => $sum('created'),
                'updated' => $sum('updated'),
                'written' => $sum('written'),
                'excluded' => $sum('excluded_total'),
                'rejected' => $sum('rejected_total'),
            ],
            'links' => [
                'types' => count($links),
                'written' => $sumLinks('written'),
                'removed' => $sumLinks('removed'),
                'excluded' => $sumLinks('excluded_total'),
                'rejected' => $sumLinks('rejected_total'),
            ],
            'anomalies' => [
                'total' => count($anomalies),
                'blocking' => $countWhere($anomalies, 'severity', Severity::Blocking->value),
                'warning' => $countWhere($anomalies, 'severity', Severity::Warning->value),
            ],
            'checks' => [
                'total' => count($checks),
                'deviations' => count($deviations),
            ],
            'longest_step' => $this->stepWithHighest($steps, 'duration_ms'),
            'memory_peak_step' => $this->stepWithHighest($steps, 'memory_peak_bytes'),
            'critical_path_ms' => $criticalPath === [] ? null : $this->between(
                $this->latestOf($stepRuns[$criticalPath[0]]->startedAt(), $run->startedAt()),
                $stepRuns[$criticalPath[array_key_last($criticalPath)]]->finishedAt(),
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildSections(WorkflowRun $run, ?WorkflowDefinition $definition): array
    {
        $sections = [];

        foreach ($definition?->report()?->sections() ?? [] as $class) {
            $title = substr(strrchr('\\' . $class, '\\'), 1);

            if (!$this->sections->has($class)) {
                $sections[] = ReportSection::unavailable($title, sprintf('The section service "%s" is not registered.', $class))->toArray();

                continue;
            }

            try {
                $sections[] = $this->sections->get($class)->build($run)->toArray();
            } catch (\Throwable $exception) {
                $sections[] = ReportSection::unavailable($title, $exception->getMessage())->toArray();
            }
        }

        return $sections;
    }

    /**
     * @param list<array<string, mixed>> $steps
     */
    private function stepWithHighest(array $steps, string $key): ?string
    {
        $best = null;

        foreach ($steps as $step) {
            if ($step[$key] !== null && ($best === null || $step[$key] > $best[$key])) {
                $best = $step;
            }
        }

        return $best['code'] ?? null;
    }

    private function latestOf(?DateTimeImmutable ...$dates): ?DateTimeImmutable
    {
        $dates = array_filter($dates);

        return $dates === [] ? null : max($dates);
    }

    private function durationMs(WorkflowStepRun $stepRun): ?int
    {
        return $stepRun->durationMs() ?? $this->between($stepRun->startedAt(), $stepRun->finishedAt());
    }

    private function between(?DateTimeImmutable $start, ?DateTimeImmutable $end): ?int
    {
        if ($start === null || $end === null) {
            return null;
        }

        return max(0, (int) $end->format('Uv') - (int) $start->format('Uv'));
    }

    private function stepName(?WorkflowDefinition $definition, string $code): string
    {
        foreach ($definition?->steps() ?? [] as $step) {
            if ($step->code() === $code) {
                return $step->name();
            }
        }

        return $code;
    }

    /**
     * Counts first, largest first; other values keep their order after them.
     */
    private static function compareAnomalyLines(int|string $left, int|string $right): int
    {
        if (is_int($left) && is_int($right)) {
            return $right <=> $left;
        }

        return is_int($right) <=> is_int($left);
    }
}
