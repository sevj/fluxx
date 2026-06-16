<?php

declare(strict_types=1);

namespace Fluxx\Operations;

use DateTimeImmutable;
use Fluxx\Ui\WorkflowRunFilters;

class WorkflowRunFilterFactory
{
    /**
     * @param array<string, mixed> $values
     */
    public function fromArray(array $values, ?string $workflowCode = null): WorkflowRunFilters
    {
        return new WorkflowRunFilters(
            searchQuery: trim((string) ($values['q'] ?? '')),
            workflowCode: $workflowCode ?? $this->normalizeString($values['workflow'] ?? null),
            status: $this->normalizeString($values['status'] ?? null),
            sourceSystem: $this->normalizeString($values['source'] ?? null),
            targetSystem: $this->normalizeString($values['target'] ?? null),
            errorPresence: $this->normalizeErrorPresence((string) ($values['errors'] ?? 'all')),
            dateFrom: $this->parseDate((string) ($values['from'] ?? ''), false),
            dateTo: $this->parseDate((string) ($values['to'] ?? ''), true),
        );
    }

    private function normalizeString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function normalizeErrorPresence(string $value): string
    {
        return in_array($value, ['all', 'with', 'without'], true) ? $value : 'all';
    }

    private function parseDate(string $value, bool $endOfDay): ?DateTimeImmutable
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $endOfDay ? $date->setTime(23, 59, 59) : $date->setTime(0, 0, 0);
    }
}
