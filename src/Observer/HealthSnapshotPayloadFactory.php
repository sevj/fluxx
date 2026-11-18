<?php

declare(strict_types=1);

namespace Fluxx\Observer;

use Fluxx\Ui\FluxxVersion;
use Fluxx\Ui\SystemHealthCheckView;
use Fluxx\Ui\SystemHealthFactView;
use Fluxx\Ui\SystemHealthFindingView;
use Fluxx\Ui\SystemHealthMetricView;
use Fluxx\Ui\SystemHealthView;

/**
 * Turns the system health page into the snapshot the observer stores and renders as is.
 * Workers and locks stay on the host: the observer only gets the facts, metrics, checks and
 * findings already summarised from them.
 */
final readonly class HealthSnapshotPayloadFactory
{
    public function __construct(
        private FluxxVersion $fluxxVersion,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function create(SystemHealthView $health): array
    {
        return [
            'ok' => $health->ok(),
            'overallState' => $health->overallState(),
            'refreshedAt' => $health->refreshedAt()->format(DATE_ATOM),
            'fluxxVersion' => $this->fluxxVersion->current(),
            'facts' => array_map(static fn (SystemHealthFactView $fact) => [
                'labelKey' => $fact->labelKey(),
                'value' => $fact->value(),
                'tone' => $fact->tone(),
            ], $health->facts()),
            'metrics' => array_map(static fn (SystemHealthMetricView $metric) => [
                'labelKey' => $metric->labelKey(),
                'value' => $metric->value(),
                'tone' => $metric->tone(),
                'hint' => $metric->hint(),
            ], $health->metrics()),
            'checks' => array_map(static fn (SystemHealthCheckView $check) => [
                'labelKey' => $check->labelKey(),
                'state' => $check->state(),
                'detail' => $check->detail(),
            ], $health->checks()),
            'findings' => array_map(static fn (SystemHealthFindingView $finding) => [
                'state' => $finding->state(),
                'title' => $finding->title(),
                'message' => $finding->message(),
            ], $health->findings()),
        ];
    }
}
