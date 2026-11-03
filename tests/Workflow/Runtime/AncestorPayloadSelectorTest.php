<?php

declare(strict_types=1);

namespace Fluxx\Tests\Workflow\Runtime;

use Fluxx\Entity\Enum\WorkflowStepType;
use Fluxx\Entity\WorkflowPayload;
use Fluxx\Workflow\Step\JoinStep;
use Fluxx\Workflow\Runtime\AncestorPayloadSelector;
use Fluxx\Workflow\WorkflowDefinition;
use Fluxx\Workflow\WorkflowStepDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AncestorPayloadSelectorTest extends TestCase
{
    #[Test]
    public function it_selects_all_chunks_sent_along_the_lineage_of_the_reader(): void
    {
        $other = $this->payload('other');
        $chunk1 = $this->payload('middle');
        $chunk2 = $this->payload('middle');

        self::assertSame([$chunk1, $chunk2], AncestorPayloadSelector::select($this->definition(), 'reader', [$other, $chunk1, $chunk2]));
    }

    #[Test]
    public function it_selects_nothing_when_no_payload_follows_the_lineage(): void
    {
        self::assertSame([], AncestorPayloadSelector::select($this->definition(), 'reader', [$this->payload('other')]));
    }

    private function payload(string $target): WorkflowPayload
    {
        $payload = $this->createStub(WorkflowPayload::class);
        $payload->method('targetStepName')->willReturn($target);

        return $payload;
    }

    private function definition(): WorkflowDefinition
    {
        $step = fn (string $code, array $dependsOn = []): WorkflowStepDefinition => new WorkflowStepDefinition($code, $code, WorkflowStepType::Read, new JoinStep($code, $code), $dependsOn);

        return new WorkflowDefinition('w', 'W', 'a', 'b', [
            $step('source'),
            $step('other', ['source']),
            $step('middle', ['source']),
            $step('reader', ['middle']),
        ]);
    }
}
