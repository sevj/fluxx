<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Step;

use Closure;
use LogicException;

final readonly class WorkflowStepInput
{
    /**
     * @param list<WorkflowStepInputPayload> $payloads
     */
    public function __construct(
        private array $payloads = [],
        private array $declaredReads = [],
        private ?Closure $ancestorRecordsLoader = null,
    ) {
    }

    /**
     * @return list<WorkflowStepInputPayload>
     */
    public function payloads(): array
    {
        return $this->payloads;
    }

    public function isEmpty(): bool
    {
        return $this->payloads === [];
    }

    public function hasPayloadFrom(string $stepCode): bool
    {
        return $this->payloadsFrom($stepCode) !== [];
    }

    /**
     * @return list<WorkflowStepInputPayload>
     */
    public function payloadsFrom(string $stepCode): array
    {
        return array_values(array_filter(
            $this->payloads,
            static fn (WorkflowStepInputPayload $payload): bool => $payload->sourceStepCode() === $stepCode,
        ));
    }

    public function firstPayloadFrom(string $stepCode): ?WorkflowStepInputPayload
    {
        return $this->payloadsFrom($stepCode)[0] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function mergedRecords(): array
    {
        $records = [];

        foreach ($this->payloads as $payload) {
            foreach (array_values($payload->records()) as $record) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recordsFrom(string $stepCode): array
    {
        $records = [];

        foreach ($this->payloadsFrom($stepCode) as $payload) {
            foreach (array_values($payload->records()) as $record) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * Preserve one array per upstream step source.
     *
     * @return array<string, array<int|string, mixed>>
     */
    public function groupedRecordsBySource(): array
    {
        $records = [];

        foreach ($this->payloads as $payload) {
            $records[$payload->sourceStepCode()] = $payload->records();
        }

        return $records;
    }

    /**
     * Output of an ancestor declared in the step's `reads`. It is loaded on each
     * call, and only when asked for, so keep the result instead of calling twice.
     *
     * @return list<array<string, mixed>>
     */
    public function recordsFromAncestor(string $stepCode): array
    {
        if (!in_array($stepCode, $this->declaredReads, true) || $this->ancestorRecordsLoader === null) {
            throw new LogicException(sprintf(
                'Step "%s" must be declared in the "reads" of the current step to be read as an ancestor.',
                $stepCode,
            ));
        }

        return ($this->ancestorRecordsLoader)($stepCode);
    }
}
