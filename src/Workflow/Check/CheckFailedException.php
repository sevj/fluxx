<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Check;

use Fluxx\Workflow\Error\BusinessWorkflowException;
use Fluxx\Workflow\Report\ReportCarryingExceptionInterface;

final class CheckFailedException extends BusinessWorkflowException implements ReportCarryingExceptionInterface
{
    public function __construct(private readonly CheckReport $checkReport)
    {
        $blocking = $checkReport->blockingDeviations();

        parent::__construct(
            sprintf('Blocking checks in deviation: %s', implode('; ', array_map(
                static fn (CheckResult $result): string => sprintf('%s (expected: %s, actual: %s)', $result->name, $result->expected ?? '-', $result->actual ?? '-'),
                $blocking,
            ))),
            'check_failed',
            ['checks' => array_map(static fn (CheckResult $result): string => $result->name, $blocking)],
        );
    }

    public function checkReport(): CheckReport
    {
        return $this->checkReport;
    }

    public function report(): array
    {
        return $this->checkReport->toArray();
    }
}
