<?php

declare(strict_types=1);

namespace Fluxx\Tests\Observer;

use DateTimeImmutable;
use Fluxx\Observer\HealthSnapshotPayloadFactory;
use Fluxx\Ui\FluxxVersion;
use Fluxx\Ui\SystemHealthCheckView;
use Fluxx\Ui\SystemHealthFactView;
use Fluxx\Ui\SystemHealthFindingView;
use Fluxx\Ui\SystemHealthMetricView;
use Fluxx\Ui\SystemHealthView;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HealthSnapshotPayloadFactoryTest extends TestCase
{
    #[Test]
    public function it_keeps_the_summaries_and_leaves_workers_and_locks_on_the_host(): void
    {
        $health = new SystemHealthView(
            ok: true,
            overallState: 'warning',
            refreshedAt: new DateTimeImmutable('2026-11-18T10:00:00+00:00'),
            summary: ['backlogCount' => 120],
            facts: [new SystemHealthFactView('system_health.fact_php', '8.5.10')],
            metrics: [new SystemHealthMetricView('system_health.metric_backlog', '120', 'warning', 'Queued messages')],
            checks: [new SystemHealthCheckView('system_health.check_workers', 'healthy', '2 workers')],
            findings: [new SystemHealthFindingView('warning', 'Backlog is growing', '120 messages are queued.')],
            workers: [['hostname' => 'worker-1.internal']],
            activeLocks: [['key' => 'contacts']],
        );

        $payload = (new HealthSnapshotPayloadFactory(new FluxxVersion()))->create($health);

        self::assertSame(true, $payload['ok']);
        self::assertSame('warning', $payload['overallState']);
        self::assertSame('2026-11-18T10:00:00+00:00', $payload['refreshedAt']);
        self::assertIsString($payload['fluxxVersion']);
        self::assertSame([['labelKey' => 'system_health.fact_php', 'value' => '8.5.10', 'tone' => 'default']], $payload['facts']);
        self::assertSame([['labelKey' => 'system_health.metric_backlog', 'value' => '120', 'tone' => 'warning', 'hint' => 'Queued messages']], $payload['metrics']);
        self::assertSame([['labelKey' => 'system_health.check_workers', 'state' => 'healthy', 'detail' => '2 workers']], $payload['checks']);
        self::assertSame([['state' => 'warning', 'title' => 'Backlog is growing', 'message' => '120 messages are queued.']], $payload['findings']);
        self::assertStringNotContainsString('worker-1.internal', json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
