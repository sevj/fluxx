<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Step;

final readonly class WorkflowStepInput
{
    /**
     * @param list<WorkflowStepInputPayload> $payloads
     */
    public function __construct(
        private array $payloads = [],
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
            array_push($records, ...$payload->records());
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
            array_push($records, ...$payload->records());
        }

        return $records;
    }
}
