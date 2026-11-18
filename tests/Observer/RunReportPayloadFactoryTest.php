<?php

declare(strict_types=1);

namespace Fluxx\Tests\Observer;

use DateTimeImmutable;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;
use Fluxx\Observer\RunReportPayloadFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RunReportPayloadFactoryTest extends TestCase
{
    #[Test]
    public function it_reports_a_completed_run_with_its_step_totals(): void
    {
        $run = $this->workflowRun('run-1');
        $run->markRunning(new DateTimeImmutable('2026-11-18T10:00:00.250+00:00'));
        $read = new WorkflowStepRun($run, 'read', 'read_contacts', 1);
        $read->scheduleRetry(new DateTimeImmutable(), new DateTimeImmutable());
        $read->markCompleted(processedCount: 10, successCount: 10);
        $write = new WorkflowStepRun($run, 'write', 'write_contacts', 2);
        $write->markCompleted(processedCount: 10, successCount: 8, errorCount: 2);
        $run->markCompleted(new DateTimeImmutable('2026-11-18T10:01:30.750+00:00'));

        $payload = (new RunReportPayloadFactory())->create($run, [$read, $write]);

        self::assertSame([
            'runId' => 'run-1',
            'workflowName' => 'contacts',
            'sourceSystem' => 'Eogile',
            'targetSystem' => 'Hubspot',
            'trigger' => 'webhook',
            'status' => 'completed',
            'batchId' => 'nightly',
            'createdAt' => $run->createdAt()->format(DATE_ATOM),
            'startedAt' => '2026-11-18T10:00:00+00:00',
            'finishedAt' => '2026-11-18T10:01:30+00:00',
            'processedCount' => 20,
            'successCount' => 18,
            'errorCount' => 2,
            'durationMs' => 90500,
            'retryCount' => 1,
            'errorCategory' => null,
            'errorCode' => null,
            'warningCount' => 0,
            'relaunchOf' => null,
        ], $payload);
    }

    #[Test]
    public function it_reduces_the_error_to_its_category_and_code(): void
    {
        $run = $this->workflowRun('run-1');
        $run->markRunning();
        $run->markFailed('SIRET 123 is invalid', errorPayload: [
            'category' => 'business',
            'class' => 'App\\InvalidSiret',
            'message' => 'SIRET 123 is invalid',
            'code' => 'invalid_siret',
            'context' => ['siret' => '123'],
        ]);

        $payload = (new RunReportPayloadFactory())->create($run, []);

        self::assertSame('failed', $payload['status']);
        self::assertSame('business', $payload['errorCategory']);
        self::assertSame('invalid_siret', $payload['errorCode']);
        self::assertStringNotContainsString('123', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function it_takes_the_error_of_the_failed_step_when_the_run_has_none(): void
    {
        $run = $this->workflowRun('run-1');
        $run->markRunning();
        $step = new WorkflowStepRun($run, 'write', 'write_contacts', 1);
        $step->markFailed('Timeout', errorPayload: ['category' => 'technical', 'code' => 'hubspot_timeout']);
        $run->markPartiallyFailed();

        $payload = (new RunReportPayloadFactory())->create($run, [$step]);

        self::assertSame('technical', $payload['errorCategory']);
        self::assertSame('hubspot_timeout', $payload['errorCode']);
    }

    #[Test]
    public function it_ignores_a_step_error_left_on_a_run_that_did_not_fail(): void
    {
        $run = $this->workflowRun('run-1');
        $run->markRunning();
        $step = new WorkflowStepRun($run, 'write', 'write_contacts', 1);
        $step->scheduleRetry(new DateTimeImmutable(), new DateTimeImmutable('+1 minute'), 'Timeout', errorPayload: ['category' => 'technical', 'code' => 'hubspot_timeout']);
        $run->markRetrying();

        $payload = (new RunReportPayloadFactory())->create($run, [$step]);

        self::assertSame('retrying', $payload['status']);
        self::assertNull($payload['errorCategory']);
        self::assertNull($payload['errorCode']);
        self::assertNull($payload['durationMs']);
    }

    #[Test]
    public function it_drops_an_unknown_category_and_shortens_a_long_code(): void
    {
        $run = $this->workflowRun('run-1');
        $run->markRunning();
        $run->markFailed(errorPayload: ['category' => 'unexpected', 'code' => str_repeat('x', 200)]);

        $payload = (new RunReportPayloadFactory())->create($run, []);

        self::assertNull($payload['errorCategory']);
        self::assertSame(120, mb_strlen((string) $payload['errorCode']));
    }

    #[Test]
    public function it_reports_the_run_a_relaunch_comes_from_and_its_warnings(): void
    {
        $run = new WorkflowRun('run-2', 'contacts', 'Eogile', 'Hubspot', 'relaunch', metadata: [
            'relaunch' => ['original_run_id' => 'run-1', 'mode' => 'resume', 'operator_user' => 'ops@example.com'],
        ]);
        $run->markRelaunched();
        $run->incrementWarningCount(3);
        $run->markCompletedWithWarnings();

        $payload = (new RunReportPayloadFactory())->create($run, []);

        self::assertSame('completed_with_warnings', $payload['status']);
        self::assertSame(3, $payload['warningCount']);
        self::assertSame('run-1', $payload['relaunchOf']);
        self::assertStringNotContainsString('ops@example.com', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function workflowRun(string $runId): WorkflowRun
    {
        return new WorkflowRun($runId, 'contacts', 'Eogile', 'Hubspot', 'webhook', 'nightly');
    }
}
