<?php

declare(strict_types=1);

namespace Fluxx\Tests\Workflow\Check;

use Fluxx\Entity\Enum\WorkflowStepType;
use Fluxx\Workflow\Check\CheckFailedException;
use Fluxx\Workflow\Check\CheckOutcome;
use Fluxx\Workflow\Check\CheckReport;
use Fluxx\Workflow\Check\CheckResult;
use Fluxx\Workflow\Error\WorkflowErrorCategory;
use Fluxx\Workflow\Report\Severity;
use Fluxx\Workflow\Report\StepReport;
use Fluxx\Workflow\Result\WorkflowStepResult;
use Fluxx\Workflow\Check\CheckStepInterface;
use Fluxx\Workflow\Step\WorkflowStepIdempotence;
use Fluxx\Workflow\Step\ExecutableWorkflowStepInterface;
use Fluxx\Workflow\WorkflowStepDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CheckReportTest extends TestCase
{
    #[Test]
    public function samples_truncated_to_20(): void
    {
        $samples = array_map(static fn (int $i): string => 'key-'.$i, range(1, 35));

        $result = new CheckResult('No duplicate', Severity::Blocking, CheckOutcome::Deviation, '0', '35', $samples);

        self::assertCount(20, $result->samples);
        self::assertSame('key-20', $result->samples[19]);
        self::assertCount(20, CheckResult::fromArray(['samples' => $samples] + $result->toArray())->samples);
    }

    #[Test]
    public function check_report_round_trips(): void
    {
        $report = (new CheckReport())
            ->add(new CheckResult('Companies present', Severity::Blocking, CheckOutcome::Ok, '12', '12'))
            ->add(new CheckResult('Main role set', Severity::Warning, CheckOutcome::Deviation, '4', '3', ['c-9']));

        $array = $report->toArray();

        self::assertSame(1, $array['v']);
        self::assertSame('check', $array['kind']);
        self::assertEquals($report, CheckReport::fromArray($array));
        self::assertSame($array, CheckReport::fromArray($array)->toArray());
    }

    #[Test]
    public function deviations_are_split_by_severity(): void
    {
        $report = (new CheckReport())
            ->add(new CheckResult('a', Severity::Blocking, CheckOutcome::Ok))
            ->add(new CheckResult('b', Severity::Blocking, CheckOutcome::Deviation))
            ->add(new CheckResult('c', Severity::Warning, CheckOutcome::Deviation))
            ->add(new CheckResult('d', Severity::Warning, CheckOutcome::Ok));

        self::assertSame(['b'], array_map(static fn (CheckResult $r): string => $r->name, $report->blockingDeviations()));
        self::assertSame(['c'], array_map(static fn (CheckResult $r): string => $r->name, $report->warningDeviations()));
    }

    #[Test]
    public function add_does_not_mutate_the_original(): void
    {
        $base = new CheckReport();
        $base->add(new CheckResult('a', Severity::Warning, CheckOutcome::Ok));

        self::assertSame([], $base->results());
    }

    #[Test]
    public function a_step_report_is_not_read_as_a_check_report(): void
    {
        $stepReport = StepReport::objects('Contacts')->toArray();

        self::assertSame('step', $stepReport['kind']);
        self::assertNull(CheckReport::fromStored($stepReport));
        self::assertNull(CheckReport::fromStored(null));

        $this->expectException(\InvalidArgumentException::class);
        CheckReport::fromArray($stepReport);
    }

    #[Test]
    public function a_check_report_is_not_read_as_a_step_report(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        StepReport::fromArray((new CheckReport())->toArray());
    }

    #[Test]
    public function a_step_report_stored_without_kind_is_still_read(): void
    {
        $legacy = StepReport::objects('Contacts')->read(2)->toArray();
        unset($legacy['kind']);

        self::assertSame(2, StepReport::fromArray($legacy)->toArray()['objects']['read']);
    }

    #[Test]
    public function invalid_stored_checks_are_rejected(): void
    {
        foreach ([
            ['v' => 2, 'kind' => 'check', 'checks' => []],
            ['v' => 1, 'kind' => 'check'],
            ['v' => 1, 'kind' => 'check', 'checks' => [['name' => 'a', 'severity' => 'fatal', 'outcome' => 'ok']]],
            ['v' => 1, 'kind' => 'check', 'checks' => [['name' => 'a', 'severity' => 'warning', 'outcome' => 'maybe']]],
            ['v' => 1, 'kind' => 'check', 'checks' => [['name' => 'a', 'severity' => 'warning', 'outcome' => 'ok', 'samples' => [['nested']]]]],
            ['v' => 1, 'kind' => 'check', 'checks' => [['name' => 'a', 'severity' => 'warning', 'outcome' => 'ok', 'samples' => 'k-1']]],
        ] as $data) {
            try {
                CheckReport::fromArray($data);
                self::fail('Expected the stored check report to be rejected.');
            } catch (\InvalidArgumentException) {
            }
        }

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function the_failure_lists_blocking_deviations_and_keeps_the_report(): void
    {
        $report = (new CheckReport())
            ->add(new CheckResult('Companies present', Severity::Blocking, CheckOutcome::Deviation, '12', '10'))
            ->add(new CheckResult('Main role set', Severity::Warning, CheckOutcome::Deviation, '4', '3'));

        $exception = new CheckFailedException($report);

        self::assertSame('Blocking checks in deviation: Companies present (expected: 12, actual: 10)', $exception->getMessage());
        self::assertSame(WorkflowErrorCategory::Business, $exception->workflowErrorCategory());
        self::assertSame($report->toArray(), $exception->report());
    }

    #[Test]
    public function a_result_cannot_carry_both_reports(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new WorkflowStepResult(report: StepReport::objects('Contacts'), checkReport: new CheckReport());
    }

    #[Test]
    public function a_check_step_needs_a_check_handler(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new WorkflowStepDefinition('verify', 'Verify', WorkflowStepType::Check, $this->createStub(ExecutableWorkflowStepInterface::class));
    }

    #[Test]
    public function integer_samples_are_kept_as_strings(): void
    {
        $result = new CheckResult('No duplicate', Severity::Blocking, CheckOutcome::Deviation, '0', '2', [1042, 1043.5, 'k-3']);
        $report = (new CheckReport())->add($result);

        self::assertSame(['1042', '1043.5', 'k-3'], $result->samples);
        self::assertSame(['1042', '1043.5', 'k-3'], CheckReport::fromArray(json_decode(json_encode($report->toArray(), JSON_THROW_ON_ERROR), true))->results()[0]->samples);
        self::assertSame(['7'], CheckReport::fromArray(['v' => 1, 'kind' => 'check', 'checks' => [['name' => 'a', 'severity' => 'warning', 'outcome' => 'deviation', 'samples' => [7]]]])->results()[0]->samples);
    }

    #[Test]
    public function a_report_only_holds_check_results(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CheckReport([['name' => 'a']]);
    }

    #[Test]
    public function a_check_step_cannot_be_idempotent(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new WorkflowStepDefinition('verify', 'Verify', WorkflowStepType::Check, $this->createStub(CheckStepInterface::class), idempotence: new WorkflowStepIdempotence());
    }
}
