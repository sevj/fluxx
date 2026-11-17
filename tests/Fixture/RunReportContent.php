<?php

declare(strict_types=1);

namespace Fluxx\Tests\Fixture;

/**
 * A stored report content (format version 1) covering every part of the report view.
 */
final class RunReportContent
{
    /**
     * @return array<string, mixed>
     */
    public static function sample(): array
    {
        return [
            'format_version' => 1,
            'summary' => [
                'run_id' => 'run-1',
                'workflow_code' => 'efficy_sync',
                'workflow_name' => 'Efficy sync',
                'trigger' => 'manual',
                'status' => 'completed_with_warnings',
                'started_at' => '2026-11-17T08:00:00+00:00',
                'finished_at' => '2026-11-17T08:01:41+00:00',
                'duration_ms' => 101000,
                'warning_count' => 1,
                'step_count' => 3,
                'objects' => ['read' => 10000, 'created' => 4, 'updated' => 7008, 'written' => 7012, 'excluded' => 2, 'rejected' => 1],
                'links' => ['types' => 1, 'written' => 6900, 'removed' => 12, 'excluded' => 0, 'rejected' => 0],
                'anomalies' => ['total' => 1, 'blocking' => 0, 'warning' => 1],
                'checks' => ['total' => 1, 'deviations' => 1, 'blocking_deviations' => 1, 'warning_deviations' => 0],
                'longest_step' => 'write',
                'memory_peak_step' => 'write',
                'critical_path_ms' => 100000,
            ],
            'objects' => [[
                'label' => 'Contacts',
                'read' => 10000,
                'created' => 4,
                'updated' => 7008,
                'written' => 7012,
                'excluded' => ['No email' => 2],
                'excluded_total' => 2,
                'rejected' => [['key' => 'c-1', 'message' => 'Invalid email', 'step' => 'write']],
                'rejected_total' => 1,
                'steps' => ['write'],
            ]],
            'links' => [[
                'label' => 'Contacts ↔ Companies',
                'read' => 6912,
                'written' => 6900,
                'removed' => 12,
                'excluded' => [],
                'excluded_total' => 0,
                'rejected' => [],
                'rejected_total' => 0,
                'steps' => ['write'],
            ]],
            'steps' => [
                ['code' => 'read', 'name' => 'Read', 'type' => 'read', 'status' => 'completed', 'duration_ms' => 10000, 'processed_count' => 10000, 'memory_peak_bytes' => 1048576, 'throughput_per_second' => 1000.0, 'critical' => true, 'relaunch' => null],
                ['code' => 'write', 'name' => 'Write', 'type' => 'write', 'status' => 'completed', 'duration_ms' => 90000, 'processed_count' => 10000, 'memory_peak_bytes' => 2097152, 'throughput_per_second' => 111.11, 'critical' => true, 'relaunch' => 'reset'],
                ['code' => 'verify', 'name' => 'Verify', 'type' => 'check', 'status' => 'completed', 'duration_ms' => 59999, 'processed_count' => 1, 'memory_peak_bytes' => null, 'throughput_per_second' => 0.02, 'critical' => false, 'relaunch' => null],
            ],
            'critical_path' => ['read', 'write'],
            'anomalies' => [
                ['step' => 'write', 'object' => 'Contacts', 'title' => 'Unresolved owners', 'severity' => 'warning', 'lines' => ['jdoe' => 3005, '<b>x</b>' => 'unknown'], 'line_total' => 25],
            ],
            'checks' => [
                ['step' => 'verify', 'name' => 'Companies present', 'severity' => 'blocking', 'outcome' => 'deviation', 'expected' => '12', 'actual' => '10', 'samples' => ['c-9']],
            ],
            'sections' => [
                ['title' => 'Mailing', 'available' => true, 'message' => null, 'lines' => [['label' => 'Sent', 'detail' => '12', 'status' => 'ok']]],
                ['title' => 'ThrowingSection', 'available' => false, 'message' => 'Source unreachable', 'lines' => []],
            ],
            'unreadable_step_reports' => [['step' => 'verify_links', 'message' => 'Unknown report kind "chart".']],
        ];
    }
}
