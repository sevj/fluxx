<?php

declare(strict_types=1);

namespace Fluxx\Tests\Entity;

use Fluxx\Entity\WorkflowRun;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkflowRunParametersTest extends TestCase
{
    #[Test]
    public function it_leaves_out_what_fluxx_records_in_the_metadata(): void
    {
        self::assertSame(['siret' => '123'], $this->workflowRun([
            'siret' => '123',
            'error' => ['message' => 'boom'],
            'relaunch' => ['original_run_id' => 'run-0'],
            'cancellation' => ['reason' => 'manual'],
        ])->launchParameters());
    }

    #[Test]
    public function it_shows_cli_parameters_next_to_the_other_ones(): void
    {
        self::assertSame(['siret' => '123', 'force_data' => true], $this->workflowRun([
            'siret' => '123',
            'parameters' => ['force_data' => true],
        ])->launchParameters());
    }

    #[Test]
    public function a_run_can_have_no_parameters(): void
    {
        self::assertSame([], $this->workflowRun([])->launchParameters());
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function workflowRun(array $metadata): WorkflowRun
    {
        return new WorkflowRun(runId: 'run-1', workflowName: 'contacts', sourceSystem: 'CSV', targetSystem: 'Hubspot', trigger: 'manual', metadata: $metadata);
    }
}
