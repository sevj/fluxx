<?php

declare(strict_types=1);

namespace Fluxx\Tests\Workflow\Report;

use DateTimeImmutable;
use Fluxx\Entity\Enum\WorkflowRunStatus;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;
use Fluxx\Tests\Fixture\FixtureWorkflow;
use Fluxx\Tests\Fixture\InMemoryStepRunLookup;
use Fluxx\Tests\Fixture\StubExecutableStep;
use Fluxx\Workflow\Check\CheckOutcome;
use Fluxx\Workflow\Check\CheckReport;
use Fluxx\Workflow\Check\CheckResult;
use Fluxx\Workflow\Report\ReportSection;
use Fluxx\Workflow\Report\RunNotReportableException;
use Fluxx\Workflow\Report\RunReportBuilder;
use Fluxx\Workflow\Report\RunReportSectionInterface;
use Fluxx\Workflow\Report\Severity;
use Fluxx\Workflow\Report\StepReport;
use Fluxx\Workflow\Report\WorkflowReportConfiguration;
use Fluxx\Workflow\SynchronizationRegistry;
use Fluxx\Workflow\WorkflowDefinition;
use Fluxx\Workflow\WorkflowStepDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class RunReportBuilderTest extends TestCase
{
    private InMemoryStepRunLookup $stepRunLookup;
    private DateTimeImmutable $origin;

    protected function setUp(): void
    {
        $this->stepRunLookup = new InMemoryStepRunLookup();
        $this->origin = new DateTimeImmutable('2026-11-17 08:00:00');
    }

    /**
     * @return iterable<string, array{WorkflowRunStatus, bool}>
     */
    public static function statuses(): iterable
    {
        foreach (WorkflowRunStatus::cases() as $status) {
            if ($status === WorkflowRunStatus::PayloadsPruned) {
                continue;
            }

            yield $status->value => [$status, $status->isCompleted()];
        }
    }

    #[Test]
    #[DataProvider('statuses')]
    public function report_built_for_completed_and_warning_runs_only(WorkflowRunStatus $status, bool $reportable): void
    {
        $run = $this->workflowRun($status);
        $this->stepRun($run, 'read', 'read', 1, '00:00', '00:10');

        if (!$reportable) {
            $this->expectException(RunNotReportableException::class);
        }

        $report = $this->builder()->build($run);

        self::assertSame($status->value, $report['summary']['status']);
    }

    #[Test]
    public function only_completed_runs_and_pruned_runs_whose_steps_ended_are_reportable(): void
    {
        foreach (WorkflowRunStatus::cases() as $status) {
            if ($status !== WorkflowRunStatus::PayloadsPruned) {
                self::assertSame($status->isCompleted(), $this->builder()->isReportable($this->workflowRun($status)), $status->value);
            }
        }

        $ended = $this->workflowRun(WorkflowRunStatus::Completed);
        $this->stepRun($ended, 'read', 'read', 1, '00:00', '00:10');
        $this->prune($ended);
        $empty = $this->workflowRun(WorkflowRunStatus::Completed);
        $this->prune($empty);
        $interrupted = $this->workflowRun(WorkflowRunStatus::Failed);
        $this->stepRun($interrupted, 'read', 'read', 1, '00:00', '00:10')->markFailed('boom');
        $this->prune($interrupted);

        self::assertTrue($this->builder()->isReportable($ended));
        self::assertFalse($this->builder()->isReportable($empty));
        self::assertFalse($this->builder()->isReportable($interrupted));
        self::assertFalse(RunReportBuilder::isReportableWith($interrupted, $this->stepRunLookup->findByWorkflowRunOrdered($interrupted)));
    }

    #[Test]
    public function a_pruned_run_without_failed_step_is_reported_with_its_warnings(): void
    {
        $run = $this->workflowRun(WorkflowRunStatus::CompletedWithWarnings);
        $this->stepRun($run, 'read', 'read', 1, '00:00', '00:10');
        $this->prune($run);

        self::assertSame('completed_with_warnings', $this->builder()->build($run)['summary']['status']);
    }

    #[Test]
    public function a_pruned_run_with_a_failed_step_is_not_reported(): void
    {
        $run = $this->workflowRun(WorkflowRunStatus::Failed);
        $this->stepRun($run, 'read', 'read', 1, '00:00', '00:10')->markFailed('boom');
        $this->prune($run);

        $this->expectException(RunNotReportableException::class);

        $this->builder()->build($run);
    }

    #[Test]
    public function objects_merge_step_reports_and_metadata_fallback(): void
    {
        $run = $this->workflowRun(WorkflowRunStatus::Completed);
        $this->stepRun($run, 'write', 'write_companies', 2, '00:10', '00:40')->replaceReport(
            StepReport::objects('Companies')
                ->read(10)
                ->created(4)
                ->updated(3)
                ->excluded('SIFUREP', 2)
                ->rejected([['key' => 'k-1', 'message' => 'Invalid SIRET']])
                ->anomaly('Unresolved owners', ['jdoe' => 3], Severity::Warning)
                ->toArray(),
        );
        $this->stepRun($run, 'write', 'write_companies_bis', 3, '00:10', '00:20')->replaceReport(
            StepReport::objects('Companies')->created(1)->excluded('SIFUREP', 1)->excluded('Closed', 2)->toArray(),
        );
        $this->stepRun($run, 'write', 'write_contacts', 4, '00:10', '00:30', [
            'created' => 5,
            'updated' => 6,
            'excluded' => ['No email' => 2, 'broken' => 'many'],
            'rejected_records' => [
                ['efficy_key' => 12, 'message' => 'Duplicate email'],
                ['key' => 'c-2', 'message' => 'Missing name'],
                ['message' => 'no key'],
                'not a record',
            ],
        ]);
        $this->stepRun($run, 'read', 'read', 1, '00:00', '00:10', ['created' => 'lots', 'note' => 'ignored']);

        $report = $this->builder()->build($run);

        self::assertSame([
            [
                'label' => 'Companies',
                'read' => 10,
                'created' => 5,
                'updated' => 3,
                'written' => 8,
                'excluded' => ['SIFUREP' => 3, 'Closed' => 2],
                'excluded_total' => 5,
                'rejected' => [['key' => 'k-1', 'message' => 'Invalid SIRET', 'step' => 'write_companies']],
                'rejected_total' => 1,
                'steps' => ['write_companies', 'write_companies_bis'],
            ],
            [
                'label' => 'write_contacts',
                'read' => 0,
                'created' => 5,
                'updated' => 6,
                'written' => 11,
                'excluded' => ['No email' => 2],
                'excluded_total' => 2,
                'rejected' => [
                    ['key' => '12', 'message' => 'Duplicate email', 'step' => 'write_contacts'],
                    ['key' => 'c-2', 'message' => 'Missing name', 'step' => 'write_contacts'],
                ],
                'rejected_total' => 2,
                'steps' => ['write_contacts'],
            ],
        ], $report['objects']);
        self::assertSame(
            ['read' => 'none', 'write_companies' => 'step', 'write_companies_bis' => 'step', 'write_contacts' => 'metadata'],
            array_column($report['steps'], 'report', 'code'),
        );
        self::assertSame(
            [['step' => 'write_companies', 'object' => 'Companies', 'title' => 'Unresolved owners', 'severity' => 'warning', 'lines' => ['jdoe' => 3], 'line_total' => 1]],
            $report['anomalies'],
        );
        self::assertSame(['read' => 10, 'created' => 10, 'updated' => 9, 'written' => 19, 'excluded' => 7, 'rejected' => 3], $report['summary']['objects']);
    }

    #[Test]
    public function links_are_reported_apart_from_objects_even_under_the_same_label(): void
    {
        $run = $this->workflowRun(WorkflowRunStatus::Completed);
        $this->stepRun($run, 'write', 'write_companies', 1, '00:00', '00:10')->replaceReport(StepReport::objects('Companies')->created(3)->toArray());
        $this->stepRun($run, 'linker', 'link_companies', 2, '00:10', '00:20')->replaceReport(
            StepReport::links('Companies')->read(9)->created(7)->removed(2)->excluded('Unknown target', 1)
                ->rejected([['key' => 'l-1', 'message' => 'Label refused']])->toArray(),
        );
        $this->stepRun($run, 'linker', 'link_companies_bis', 3, '00:20', '00:30')->replaceReport(StepReport::links('Companies')->created(1)->toArray());

        $report = $this->builder()->build($run);

        self::assertSame(['Companies'], array_column($report['objects'], 'label'));
        self::assertSame(3, $report['objects'][0]['written']);
        self::assertSame([[
            'label' => 'Companies',
            'read' => 9,
            'written' => 8,
            'removed' => 2,
            'excluded' => ['Unknown target' => 1],
            'excluded_total' => 1,
            'rejected' => [['key' => 'l-1', 'message' => 'Label refused', 'step' => 'link_companies']],
            'rejected_total' => 1,
            'steps' => ['link_companies', 'link_companies_bis'],
        ]], $report['links']);
        self::assertSame(['types' => 1, 'written' => 8, 'removed' => 2, 'excluded' => 1, 'rejected' => 1], $report['summary']['links']);
        self::assertSame(3, $report['summary']['objects']['written']);
        self::assertSame(0, $report['summary']['objects']['rejected']);
    }

    #[Test]
    public function rejected_objects_are_capped_but_counted(): void
    {
        $run = $this->workflowRun(WorkflowRunStatus::Completed);
        $rejected = array_map(static fn (int $i): array => ['key' => 'k-' . $i, 'message' => 'Invalid'], range(1, 80));
        $this->stepRun($run, 'write', 'first', 1, '00:00', '00:10')->replaceReport(StepReport::objects('Contacts')->rejected($rejected)->toArray());
        $this->stepRun($run, 'write', 'second', 2, '00:00', '00:10')->replaceReport(StepReport::objects('Contacts')->rejected($rejected)->toArray());

        $object = $this->builder()->build($run)['objects'][0];

        self::assertCount(RunReportBuilder::MAX_REJECTED_PER_OBJECT, $object['rejected']);
        self::assertSame(160, $object['rejected_total']);
        self::assertSame('second', $object['rejected'][99]['step']);
    }

    #[Test]
    public function an_unreadable_step_report_is_listed_and_the_report_still_built(): void
    {
        $run = $this->workflowRun(WorkflowRunStatus::Completed);
        $this->stepRun($run, 'write', 'write_contacts', 1, '00:00', '00:10', ['created' => 3])->replaceReport(['v' => 1, 'objects' => ['label' => 'Contacts', 'excluded' => 'broken']]);
        $this->stepRun($run, 'check', 'verify', 2, '00:10', '00:20')->replaceReport(['v' => 1, 'kind' => 'check', 'checks' => 'broken']);
        $this->stepRun($run, 'write', 'write_notes', 3, '00:10', '00:20')->replaceReport(['v' => 1, 'kind' => 'chart']);

        $report = $this->builder()->build($run);

        self::assertSame(['write_contacts', 'verify', 'write_notes'], array_column($report['unreadable_step_reports'], 'step'));
        self::assertSame('Unknown report kind "chart".', $report['unreadable_step_reports'][2]['message']);
        self::assertSame(['unreadable', 'unreadable', 'unreadable'], array_column($report['steps'], 'report'));
        self::assertSame([], $report['objects']);
    }

    #[Test]
    public function checks_are_listed_with_their_step(): void
    {
        $run = $this->workflowRun(WorkflowRunStatus::CompletedWithWarnings);
        $this->stepRun($run, 'check', 'verify', 1, '00:00', '00:10')->replaceReport((new CheckReport())
            ->add(new CheckResult('Companies present', CheckOutcome::Ok, '12', '12'))
            ->add(new CheckResult('Main role set', CheckOutcome::Deviation, '4', '3', ['c-9']))
            ->toArray());

        $report = $this->builder()->build($run);

        self::assertSame(
            ['step' => 'verify', 'name' => 'Main role set', 'outcome' => 'deviation', 'expected' => '4', 'actual' => '3', 'samples' => ['c-9'], 'subject' => null, 'hint' => null],
            $report['checks'][1],
        );
        self::assertSame(['total' => 2, 'deviations' => 1], $report['summary']['checks']);
    }

    #[Test]
    public function steps_carry_timing_counters_throughput_and_the_critical_path(): void
    {
        $run = $this->workflowRun(WorkflowRunStatus::Completed);
        $this->stepRun($run, 'read', 'read', 1, '00:00', '00:10', processed: 50, memory: 2048);
        $this->stepRun($run, 'write', 'write', 2, '00:10', '01:50', processed: 400, memory: 4096);
        $this->stepRun($run, 'check', 'verify', 3, '00:10', '00:20', processed: 0);

        $report = $this->builder($this->definition([
            $this->step('read'),
            $this->step('write', ['read']),
            $this->step('verify', ['read']),
        ]))->build($run);

        self::assertSame([
            'code' => 'write',
            'name' => 'Write',
            'type' => 'write',
            'status' => 'completed',
            'started_at' => '2026-11-17T08:00:10+00:00',
            'finished_at' => '2026-11-17T08:01:50+00:00',
            'duration_ms' => 100000,
            'processed_count' => 400,
            'success_count' => 400,
            'error_count' => 0,
            'memory_peak_bytes' => 4096,
            'throughput_per_second' => 4.0,
            'report' => 'none',
            'critical' => true,
            'relaunch' => null,
        ], $report['steps'][1]);
        self::assertSame(['read', 'write'], $report['critical_path']);
        self::assertFalse($report['steps'][2]['critical']);
        self::assertSame(0.0, $report['steps'][2]['throughput_per_second']);
        self::assertSame('write', $report['summary']['longest_step']);
        self::assertSame('write', $report['summary']['memory_peak_step']);
        self::assertSame(110000, $report['summary']['critical_path_ms']);
        self::assertSame('Sync', $report['summary']['workflow_name']);
        self::assertSame(1, $report['format_version']);
    }

    #[Test]
    public function anomaly_lines_are_capped_but_counted(): void
    {
        $run = $this->workflowRun(WorkflowRunStatus::Completed);
        $lines = [];
        foreach (range(1, 30) as $i) {
            $lines['owner-' . $i] = $i;
        }
        $this->stepRun($run, 'write', 'write', 1, '00:00', '00:10')->replaceReport(StepReport::objects('Contacts')->anomaly('Unresolved owners', $lines)->toArray());

        $anomaly = $this->builder()->build($run)['anomalies'][0];

        self::assertCount(RunReportBuilder::MAX_ANOMALY_LINES, $anomaly['lines']);
        self::assertSame(20, $anomaly['lines']['owner-20']);
        self::assertSame(30, $anomaly['line_total']);
    }

    #[Test]
    public function a_relaunched_run_leaves_its_preserved_steps_out_of_the_critical_path(): void
    {
        $run = new WorkflowRun('run-2', 'sync', 'src', 'tgt', 'relaunch');
        $run->markRunning($this->at('10:00'));
        $run->markCompleted($this->at('11:00'));
        $this->stepRun($run, 'read', 'read', 1, '00:00', '05:00', ['relaunch' => ['status' => 'preserved', 'original_run_id' => 'run-1']]);
        $this->stepRun($run, 'write', 'write', 2, '10:00', '10:30', ['relaunch' => ['status' => 'reset', 'mode' => 'step']]);
        $this->stepRun($run, 'check', 'verify', 3, '10:00', '10:50');

        $report = $this->builder($this->definition([
            $this->step('read'),
            $this->step('write', ['read']),
            $this->step('verify', ['write']),
        ]))->build($run);

        self::assertSame(['write', 'verify'], $report['critical_path']);
        self::assertSame(50000, $report['summary']['critical_path_ms']);
        self::assertSame(['read' => 'preserved', 'write' => 'reset', 'verify' => null], array_column($report['steps'], 'relaunch', 'code'));
        self::assertFalse($report['steps'][0]['critical']);
    }

    #[Test]
    public function the_critical_path_starts_no_earlier_than_the_run(): void
    {
        $run = new WorkflowRun('run-2', 'sync', 'src', 'tgt', 'relaunch');
        $run->markRunning($this->at('00:30'));
        $run->markCompleted($this->at('01:00'));
        $this->stepRun($run, 'read', 'read', 1, '00:00', '00:50');

        $report = $this->builder($this->definition([$this->step('read')]))->build($run);

        self::assertSame(['read'], $report['critical_path']);
        self::assertSame(20000, $report['summary']['critical_path_ms']);
    }

    #[Test]
    public function failing_section_is_marked_unavailable(): void
    {
        $run = $this->workflowRun(WorkflowRunStatus::Completed);
        $definition = $this->definition([$this->step('read')], new WorkflowReportConfiguration([
            ValidSection::class,
            FailingSection::class,
            MalformedSection::class,
            UnregisteredSection::class,
        ]));
        $sections = new ServiceLocator([
            ValidSection::class => static fn (): ValidSection => new ValidSection(),
            FailingSection::class => static fn (): FailingSection => new FailingSection(),
            MalformedSection::class => static fn (): MalformedSection => new MalformedSection(),
        ]);

        $report = $this->builder($definition, $sections)->build($run);

        self::assertSame([
            ['title' => 'Mailing', 'available' => true, 'message' => null, 'lines' => [['label' => 'Sent', 'detail' => '12', 'status' => 'ok']]],
            ['title' => 'FailingSection', 'available' => false, 'message' => 'HubSpot is down', 'lines' => []],
            ['title' => 'MalformedSection', 'available' => false, 'message' => 'Each line of report section "Broken" needs a label.', 'lines' => []],
            ['title' => 'UnregisteredSection', 'available' => false, 'message' => sprintf('The section service "%s" is not registered.', UnregisteredSection::class), 'lines' => []],
        ], $report['sections']);
    }

    #[Test]
    public function a_section_class_must_implement_the_section_interface(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new WorkflowReportConfiguration([\stdClass::class]);
    }

    private function builder(?WorkflowDefinition $definition = null, ?ServiceLocator $sections = null): RunReportBuilder
    {
        return new RunReportBuilder(
            new SynchronizationRegistry([new FixtureWorkflow($definition ?? $this->definition([]))]),
            $this->stepRunLookup,
            $sections ?? new ServiceLocator([]),
        );
    }

    /**
     * @param list<WorkflowStepDefinition> $steps
     */
    private function definition(array $steps, ?WorkflowReportConfiguration $report = null): WorkflowDefinition
    {
        return new WorkflowDefinition('sync', 'Sync', 'src', 'tgt', $steps, report: $report ?? new WorkflowReportConfiguration());
    }

    /**
     * @param list<string> $dependsOn
     */
    private function step(string $code, array $dependsOn = []): WorkflowStepDefinition
    {
        return new WorkflowStepDefinition($code, ucfirst($code), 'transform', new StubExecutableStep($code, $code), $dependsOn);
    }

    private function workflowRun(WorkflowRunStatus $status): WorkflowRun
    {
        $run = new WorkflowRun('run-1', 'sync', 'src', 'tgt', 'manual');
        $run->markRunning($this->origin);

        match ($status) {
            WorkflowRunStatus::Completed => $run->markCompleted($this->origin->modify('+2 minutes')),
            WorkflowRunStatus::CompletedWithWarnings => $run->markCompletedWithWarnings($this->origin->modify('+2 minutes')),
            WorkflowRunStatus::Failed => $run->markFailed('boom'),
            WorkflowRunStatus::PartiallyFailed => $run->markPartiallyFailed('boom'),
            WorkflowRunStatus::Cancelled => $run->markCancelled(),
            WorkflowRunStatus::Retrying => $run->markRetrying(),
            WorkflowRunStatus::Relaunched => $run->markRelaunched(),
            WorkflowRunStatus::PayloadsPruned => $this->prune($run),
            WorkflowRunStatus::Pending, WorkflowRunStatus::Running => null,
        };

        return $run;
    }

    /**
     * The status set by pruning payloads, which no entity method sets.
     */
    private function prune(WorkflowRun $run): void
    {
        (new \ReflectionProperty(WorkflowRun::class, 'status'))->setValue($run, WorkflowRunStatus::PayloadsPruned);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function stepRun(
        WorkflowRun $run,
        string $type,
        string $code,
        int $position,
        string $start,
        string $end,
        array $metadata = [],
        int $processed = 1,
        ?int $memory = null,
    ): WorkflowStepRun {
        $stepRun = new WorkflowStepRun($run, $type, $code, $position, $metadata);
        $startedAt = $this->at($start);
        $finishedAt = $this->at($end);
        $stepRun->markRunning($startedAt);
        $stepRun->markCompleted(
            $processed,
            $processed,
            durationMs: ((int) $finishedAt->format('U') - (int) $startedAt->format('U')) * 1000,
            memoryPeakBytes: $memory,
            finishedAt: $finishedAt,
        );
        $this->stepRunLookup->add($stepRun);

        return $stepRun;
    }

    private function at(string $offset): DateTimeImmutable
    {
        [$minutes, $seconds] = array_map('intval', explode(':', $offset));

        return $this->origin->modify(sprintf('+%d seconds', $minutes * 60 + $seconds));
    }
}

final class ValidSection implements RunReportSectionInterface
{
    public function build(WorkflowRun $run): ReportSection
    {
        return new ReportSection('Mailing', [['label' => 'Sent', 'detail' => '12', 'status' => 'ok']]);
    }
}

final class FailingSection implements RunReportSectionInterface
{
    public function build(WorkflowRun $run): ReportSection
    {
        throw new \RuntimeException('HubSpot is down');
    }
}

final class MalformedSection implements RunReportSectionInterface
{
    public function build(WorkflowRun $run): ReportSection
    {
        return new ReportSection('Broken', [['detail' => 'no label']]);
    }
}

final class UnregisteredSection implements RunReportSectionInterface
{
    public function build(WorkflowRun $run): ReportSection
    {
        return new ReportSection('Never built');
    }
}
