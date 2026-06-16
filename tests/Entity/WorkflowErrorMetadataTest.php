<?php

declare(strict_types=1);

namespace Fluxx\Tests\Entity;

use Fluxx\Entity\Enum\WorkflowRunStatus;
use Fluxx\Entity\Enum\WorkflowStepRunStatus;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkflowErrorMetadataTest extends TestCase
{
    #[Test]
    public function workflow_run_stores_and_clears_error_payloads(): void
    {
        $run = new WorkflowRun(
            runId: 'run-1',
            workflowName: 'contacts',
            sourceSystem: 'CSV',
            targetSystem: 'Hubspot',
            trigger: 'manual',
        );

        $run->markFailed('boom', errorPayload: ['category' => 'technical', 'code' => 'X']);
        self::assertSame('technical', $run->errorPayload()['category']);

        $run->markRunning();
        self::assertNull($run->errorPayload());
    }

    #[Test]
    public function workflow_run_exposes_relaunch_state_and_metadata(): void
    {
        $run = new WorkflowRun(
            runId: 'run-2',
            workflowName: 'contacts',
            sourceSystem: 'CSV',
            targetSystem: 'Hubspot',
            trigger: 'relaunch',
            metadata: [
                'relaunch' => [
                    'original_run_id' => 'run-1',
                    'mode' => 'step',
                ],
            ],
        );

        $run->markRelaunched();

        self::assertSame(WorkflowRunStatus::Relaunched, $run->status());
        self::assertSame('run-1', $run->relaunchMetadata()['original_run_id']);
    }

    #[Test]
    public function workflow_step_run_stores_and_clears_error_payloads(): void
    {
        $stepRun = new WorkflowStepRun(
            workflowRun: new WorkflowRun(
                runId: 'run-1',
                workflowName: 'contacts',
                sourceSystem: 'CSV',
                targetSystem: 'Hubspot',
                trigger: 'manual',
            ),
            stepType: 'write',
            stepName: 'write_contacts',
            position: 1,
        );

        $stepRun->markFailed('boom', errorPayload: ['category' => 'business', 'code' => 'CONTACT_INVALID']);
        self::assertSame('business', $stepRun->errorPayload()['category']);

        $stepRun->markCompleted(1, 1);
        self::assertNull($stepRun->errorPayload());
    }

    #[Test]
    public function workflow_step_run_can_be_marked_as_relaunched(): void
    {
        $stepRun = new WorkflowStepRun(
            workflowRun: new WorkflowRun(
                runId: 'run-3',
                workflowName: 'contacts',
                sourceSystem: 'CSV',
                targetSystem: 'Hubspot',
                trigger: 'relaunch',
            ),
            stepType: 'write',
            stepName: 'write_contacts',
            position: 1,
        );

        $stepRun->markRelaunched();

        self::assertSame(WorkflowStepRunStatus::Relaunched, $stepRun->status());
    }
}
