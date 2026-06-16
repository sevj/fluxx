<?php

declare(strict_types=1);

namespace Fluxx\Ui;

use Composer\InstalledVersions;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Fluxx\Operations\RuntimeInspector;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Kernel;

final readonly class SystemHealth
{
    public function __construct(
        private RuntimeInspector $runtimeInspector,
        private Connection $connection,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
        #[Autowire('%kernel.environment%')]
        private string $environment,
    ) {
    }

    public function current(): SystemHealthView
    {
        $snapshot = $this->runtimeInspector->snapshot();
        $refreshedAt = new DateTimeImmutable((string) ($snapshot['refreshedAt'] ?? 'now'));
        $summary = is_array($snapshot['summary'] ?? null) ? $snapshot['summary'] : [];
        $workers = is_array($snapshot['workers'] ?? null) ? $snapshot['workers'] : [];
        $activeLocks = is_array($snapshot['activeLocks'] ?? null) ? $snapshot['activeLocks'] : [];
        $findings = [];

        if (($snapshot['ok'] ?? false) !== true) {
            $findings[] = new SystemHealthFindingView(
                'critical',
                'Runtime snapshot failed',
                (string) ($snapshot['error'] ?? 'The runtime snapshot could not be produced.'),
            );

            return new SystemHealthView(
                ok: false,
                overallState: 'critical',
                refreshedAt: $refreshedAt,
                summary: $summary,
                facts: $this->buildFacts($summary),
                metrics: $this->buildMetrics($summary, $findings),
                checks: $this->buildChecks($summary, $workers, $activeLocks, $findings, false, $refreshedAt),
                findings: $findings,
                workers: $workers,
                activeLocks: $activeLocks,
            );
        }

        $backlogCount = (int) ($summary['backlogCount'] ?? 0);
        $inFlightCount = (int) ($summary['inFlightCount'] ?? 0);
        $consumerCount = (int) ($summary['consumerCount'] ?? 0);
        $activeLockCount = (int) ($summary['activeLockCount'] ?? 0);
        $oldestPendingAgeMs = isset($summary['oldestPendingAgeMs']) ? (int) $summary['oldestPendingAgeMs'] : null;

        if ($consumerCount === 0) {
            $findings[] = new SystemHealthFindingView(
                ($backlogCount > 0 || $inFlightCount > 0) ? 'critical' : 'warning',
                'No active worker detected',
                ($backlogCount > 0 || $inFlightCount > 0)
                    ? 'The queue still has work but no active worker is visible.'
                    : 'No active worker is visible for the Fluxx transport.',
            );
        }

        if ($backlogCount >= 500) {
            $findings[] = new SystemHealthFindingView('critical', 'Backlog is high', sprintf('%d messages are queued in the Fluxx transport.', $backlogCount));
        } elseif ($backlogCount >= 100) {
            $findings[] = new SystemHealthFindingView('warning', 'Backlog is growing', sprintf('%d messages are queued in the Fluxx transport.', $backlogCount));
        }

        if ($oldestPendingAgeMs !== null) {
            if ($oldestPendingAgeMs >= 300000) {
                $findings[] = new SystemHealthFindingView('critical', 'Pending messages are too old', sprintf('The oldest pending message has been waiting for %d seconds.', (int) floor($oldestPendingAgeMs / 1000)));
            } elseif ($oldestPendingAgeMs >= 60000) {
                $findings[] = new SystemHealthFindingView('warning', 'Pending messages are aging', sprintf('The oldest pending message has been waiting for %d seconds.', (int) floor($oldestPendingAgeMs / 1000)));
            }
        }

        foreach ($workers as $worker) {
            $workerState = (string) ($worker['state'] ?? '');

            if ($workerState === 'stopped') {
                $findings[] = new SystemHealthFindingView(
                    'critical',
                    'A worker is stopped',
                    sprintf('Worker "%s" is marked as stopped.', (string) ($worker['name'] ?? 'unknown')),
                );
            }
        }

        foreach ($activeLocks as $lock) {
            $acquiredAt = isset($lock['acquiredAt']) && is_string($lock['acquiredAt']) ? new DateTimeImmutable($lock['acquiredAt']) : null;

            if ($acquiredAt === null) {
                continue;
            }

            $ageSeconds = max($refreshedAt->getTimestamp() - $acquiredAt->getTimestamp(), 0);

            if ($ageSeconds >= 1800) {
                $findings[] = new SystemHealthFindingView(
                    'critical',
                    'A lock is held for too long',
                    sprintf('Lock "%s" has been held for %d minutes.', (string) ($lock['lockKey'] ?? 'unknown'), (int) floor($ageSeconds / 60)),
                );
            } elseif ($ageSeconds >= 600) {
                $findings[] = new SystemHealthFindingView(
                    'warning',
                    'A lock is older than expected',
                    sprintf('Lock "%s" has been held for %d minutes.', (string) ($lock['lockKey'] ?? 'unknown'), (int) floor($ageSeconds / 60)),
                );
            }
        }

        if ($activeLockCount > 0 && $findings === []) {
            $findings[] = new SystemHealthFindingView(
                'warning',
                'Active locks present',
                sprintf('%d active workflow lock(s) are currently held.', $activeLockCount),
            );
        }

        $overallState = 'healthy';

        foreach ($findings as $finding) {
            if ($finding->state() === 'critical') {
                $overallState = 'critical';
                break;
            }

            if ($finding->state() === 'warning') {
                $overallState = 'warning';
            }
        }

        return new SystemHealthView(
            ok: true,
            overallState: $overallState,
            refreshedAt: $refreshedAt,
            summary: $summary,
            facts: $this->buildFacts($summary),
            metrics: $this->buildMetrics($summary, $findings),
            checks: $this->buildChecks($summary, $workers, $activeLocks, $findings, true, $refreshedAt),
            findings: $findings,
            workers: $workers,
            activeLocks: $activeLocks,
        );
    }

    /**
     * @param array<string, mixed> $summary
     * @return list<SystemHealthFactView>
     */
    private function buildFacts(array $summary): array
    {
        $backlogCount = (int) ($summary['backlogCount'] ?? 0);
        $oldestPendingAgeMs = isset($summary['oldestPendingAgeMs']) ? (int) $summary['oldestPendingAgeMs'] : null;
        $failedMessageCount = $this->failedMessageCount();

        return [
            new SystemHealthFactView('system_health.fact_php', PHP_VERSION),
            new SystemHealthFactView('system_health.fact_symfony', $this->symfonyVersion()),
            new SystemHealthFactView('system_health.fact_environment', $this->environment),
            new SystemHealthFactView('system_health.fact_opcache', $this->opcacheStatus(), $this->opcacheEnabled() ? 'default' : 'warning'),
            new SystemHealthFactView('system_health.fact_db_size', $this->databaseSize()),
            new SystemHealthFactView('system_health.fact_cache', $this->formatBytes($this->directorySize($this->projectDir.'/var/cache'))),
            new SystemHealthFactView('system_health.fact_logs', $this->formatBytes($this->directorySize($this->projectDir.'/var/log'))),
            new SystemHealthFactView('system_health.fact_disk', $this->diskUsage()),
            new SystemHealthFactView('system_health.fact_migrations', $this->migrationStatus()),
        ];
    }

    /**
     * @param array<string, mixed> $summary
     * @param list<SystemHealthFindingView> $findings
     * @return list<SystemHealthMetricView>
     */
    private function buildMetrics(array $summary, array $findings): array
    {
        $criticalCount = 0;
        $warningCount = 0;

        foreach ($findings as $finding) {
            if ($finding->state() === 'critical') {
                ++$criticalCount;
                continue;
            }

            if ($finding->state() === 'warning') {
                ++$warningCount;
            }
        }

        $backlogCount = (int) ($summary['backlogCount'] ?? 0);
        $inFlightCount = (int) ($summary['inFlightCount'] ?? 0);
        $consumerCount = (int) ($summary['consumerCount'] ?? 0);
        $activeLockCount = (int) ($summary['activeLockCount'] ?? 0);
        $oldestPendingAgeMs = isset($summary['oldestPendingAgeMs']) ? (int) $summary['oldestPendingAgeMs'] : null;

        return [
            new SystemHealthMetricView('system_health.metric_snapshot', $criticalCount > 0 ? 'degraded' : 'ok', $criticalCount > 0 ? 'error' : 'default'),
            new SystemHealthMetricView('system_health.metric_consumers', (string) $consumerCount, $consumerCount === 0 ? 'error' : 'default'),
            new SystemHealthMetricView('system_health.metric_backlog', (string) $backlogCount, $backlogCount >= 500 ? 'error' : ($backlogCount >= 100 ? 'warning' : 'default')),
            new SystemHealthMetricView('system_health.metric_in_flight', (string) $inFlightCount),
            new SystemHealthMetricView('system_health.metric_active_locks', (string) $activeLockCount, $activeLockCount > 0 ? 'warning' : 'default'),
            new SystemHealthMetricView('system_health.metric_oldest_pending', $this->formatDuration($oldestPendingAgeMs), $oldestPendingAgeMs !== null && $oldestPendingAgeMs >= 300000 ? 'error' : ($oldestPendingAgeMs !== null && $oldestPendingAgeMs >= 60000 ? 'warning' : 'default')),
            new SystemHealthMetricView('system_health.metric_warning_count', (string) $warningCount, $warningCount > 0 ? 'warning' : 'default'),
            new SystemHealthMetricView('system_health.metric_critical_count', (string) $criticalCount, $criticalCount > 0 ? 'error' : 'default'),
        ];
    }

    /**
     * @param array<string, mixed> $summary
     * @param list<array<string, mixed>> $workers
     * @param list<array<string, mixed>> $activeLocks
     * @param list<SystemHealthFindingView> $findings
     * @return list<SystemHealthCheckView>
     */
    private function buildChecks(
        array $summary,
        array $workers,
        array $activeLocks,
        array $findings,
        bool $snapshotOk,
        DateTimeImmutable $refreshedAt,
    ): array {
        $backlogCount = (int) ($summary['backlogCount'] ?? 0);
        $inFlightCount = (int) ($summary['inFlightCount'] ?? 0);
        $consumerCount = (int) ($summary['consumerCount'] ?? 0);
        $oldestPendingAgeMs = isset($summary['oldestPendingAgeMs']) ? (int) $summary['oldestPendingAgeMs'] : null;

        $stoppedWorkers = 0;
        $staleWorkers = 0;

        foreach ($workers as $worker) {
            if (($worker['state'] ?? null) === 'stopped') {
                ++$stoppedWorkers;
            }

            if (!isset($worker['lastSeenAt']) || !is_string($worker['lastSeenAt'])) {
                continue;
            }

            $lastSeenAt = new DateTimeImmutable($worker['lastSeenAt']);
            $ageSeconds = max($refreshedAt->getTimestamp() - $lastSeenAt->getTimestamp(), 0);

            if ($ageSeconds >= 120) {
                ++$staleWorkers;
            }
        }

        $oldLocks = 0;

        foreach ($activeLocks as $lock) {
            if (!isset($lock['acquiredAt']) || !is_string($lock['acquiredAt'])) {
                continue;
            }

            $acquiredAt = new DateTimeImmutable($lock['acquiredAt']);
            $ageSeconds = max($refreshedAt->getTimestamp() - $acquiredAt->getTimestamp(), 0);

            if ($ageSeconds >= 600) {
                ++$oldLocks;
            }
        }

        return [
            new SystemHealthCheckView(
                'system_health.check_snapshot',
                $snapshotOk ? 'healthy' : 'critical',
                $snapshotOk ? 'The runtime snapshot is available.' : 'The runtime snapshot could not be produced.',
            ),
            new SystemHealthCheckView(
                'system_health.check_workers',
                $consumerCount === 0 ? (($backlogCount > 0 || $inFlightCount > 0) ? 'critical' : 'warning') : (($stoppedWorkers > 0 || $staleWorkers > 0) ? 'warning' : 'healthy'),
                $consumerCount === 0
                    ? 'No active worker is currently visible.'
                    : sprintf('%d consumer(s), %d stopped, %d stale heartbeat(s).', $consumerCount, $stoppedWorkers, $staleWorkers),
            ),
            new SystemHealthCheckView(
                'system_health.check_queue_pressure',
                $backlogCount >= 500 ? 'critical' : ($backlogCount >= 100 ? 'warning' : 'healthy'),
                sprintf('%d queued message(s) are waiting in the transport.', $backlogCount),
            ),
            new SystemHealthCheckView(
                'system_health.check_queue_latency',
                $oldestPendingAgeMs !== null && $oldestPendingAgeMs >= 300000 ? 'critical' : ($oldestPendingAgeMs !== null && $oldestPendingAgeMs >= 60000 ? 'warning' : 'healthy'),
                $oldestPendingAgeMs === null
                    ? 'No pending message age is currently available.'
                    : sprintf('Oldest pending message age: %s.', $this->formatDuration($oldestPendingAgeMs)),
            ),
            new SystemHealthCheckView(
                'system_health.check_locks',
                $oldLocks > 0 ? 'warning' : 'healthy',
                sprintf('%d active lock(s), %d older than 10 minutes.', count($activeLocks), $oldLocks),
            ),
            new SystemHealthCheckView(
                'system_health.check_alerts',
                $this->alertsState($findings),
                sprintf('%d warning(s), %d critical alert(s).', $this->countFindings($findings, 'warning'), $this->countFindings($findings, 'critical')),
            ),
        ];
    }

    /**
     * @param list<SystemHealthFindingView> $findings
     */
    private function alertsState(array $findings): string
    {
        if ($this->countFindings($findings, 'critical') > 0) {
            return 'critical';
        }

        if ($this->countFindings($findings, 'warning') > 0) {
            return 'warning';
        }

        return 'healthy';
    }

    /**
     * @param list<SystemHealthFindingView> $findings
     */
    private function countFindings(array $findings, string $state): int
    {
        $count = 0;

        foreach ($findings as $finding) {
            if ($finding->state() === $state) {
                ++$count;
            }
        }

        return $count;
    }

    private function formatDuration(?int $durationMs): string
    {
        if ($durationMs === null) {
            return '-';
        }

        $seconds = (int) floor($durationMs / 1000);

        if ($seconds < 60) {
            return sprintf('%ds', $seconds);
        }

        $minutes = (int) floor($seconds / 60);
        $remainingSeconds = $seconds % 60;

        if ($minutes < 60) {
            return $remainingSeconds > 0 ? sprintf('%dm %02ds', $minutes, $remainingSeconds) : sprintf('%dm', $minutes);
        }

        $hours = (int) floor($minutes / 60);
        $remainingMinutes = $minutes % 60;

        return $remainingMinutes > 0 ? sprintf('%dh %02dm', $hours, $remainingMinutes) : sprintf('%dh', $hours);
    }

    private function applicationVersion(): string
    {
        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('sevj/fluxx')) {
            $prettyVersion = InstalledVersions::getPrettyVersion('sevj/fluxx') ?? 'dev';
            $reference = InstalledVersions::getReference('sevj/fluxx');

            if (is_string($reference) && $reference !== '') {
                return sprintf('%s (%s)', $prettyVersion, substr($reference, 0, 7));
            }

            return $prettyVersion;
        }

        return 'dev';
    }

    private function symfonyVersion(): string
    {
        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('symfony/framework-bundle')) {
            return InstalledVersions::getPrettyVersion('symfony/framework-bundle') ?? Kernel::VERSION;
        }

        return Kernel::VERSION;
    }

    private function opcacheStatus(): string
    {
        return $this->opcacheEnabled() ? 'enabled' : 'disabled';
    }

    private function opcacheEnabled(): bool
    {
        if (!function_exists('opcache_get_status')) {
            return false;
        }

        $status = opcache_get_status(false);

        return is_array($status) && ($status['opcache_enabled'] ?? false) === true;
    }

    private function failedMessageCount(): int
    {
        try {
            return (int) $this->connection->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'failed'");
        } catch (\Throwable) {
            return 0;
        }
    }

    private function databaseSize(): string
    {
        try {
            $platform = $this->connection->getDatabasePlatform();

            if ($platform instanceof PostgreSQLPlatform) {
                return $this->formatBytes((int) $this->connection->fetchOne('SELECT pg_database_size(current_database())'));
            }

            if ($platform instanceof SQLitePlatform) {
                return $this->sqliteDatabaseSize();
            }

            if ($platform instanceof AbstractMySQLPlatform) {
                return $this->mysqlDatabaseSize();
            }

            return '-';
        } catch (\Throwable) {
            return '-';
        }
    }

    private function sqliteDatabaseSize(): string
    {
        $params = $this->connection->getParams();
        $path = $params['path'] ?? null;

        if (!is_string($path) || $path === '' || !is_file($path)) {
            return '-';
        }

        return $this->formatBytes(filesize($path) ?: 0);
    }

    private function mysqlDatabaseSize(): string
    {
        $databaseName = $this->connection->getDatabase();

        if ($databaseName === null || $databaseName === '') {
            return '-';
        }

        $bytes = $this->connection->fetchOne(
            'SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.TABLES WHERE table_schema = ?',
            [$databaseName],
        );

        return $this->formatBytes((int) $bytes);
    }

    private function diskUsage(): string
    {
        $free = @disk_free_space($this->projectDir);
        $total = @disk_total_space($this->projectDir);

        if (!is_float($free) && !is_int($free) || !is_float($total) && !is_int($total) || $total <= 0) {
            return '-';
        }

        $usedPercent = (int) round((1 - ($free / $total)) * 100);

        return sprintf('%s free (%d%% used)', $this->formatBytes((int) $free), $usedPercent);
    }

    private function migrationStatus(): string
    {
        $available = $this->countMigrationFiles($this->projectDir.'/migrations');
        $executed = $this->executedMigrationCount();

        if ($available === null && $executed === null) {
            return '-';
        }

        $available ??= $executed ?? 0;
        $executed ??= 0;

        return $executed >= $available
            ? sprintf('%d/%d %s', $executed, $available, $available > 0 ? '✓' : '')
            : sprintf('%d/%d', $executed, $available);
    }

    private function executedMigrationCount(): ?int
    {
        try {
            $schemaManager = $this->connection->createSchemaManager();

            if (!$schemaManager->tablesExist(['doctrine_migration_versions'])) {
                return null;
            }

            return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM doctrine_migration_versions');
        } catch (\Throwable) {
            return null;
        }
    }

    private function countMigrationFiles(string $path): ?int
    {
        if (!is_dir($path)) {
            return null;
        }

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                ++$count;
            }
        }

        return $count;
    }

    private function directorySize(string $path): int
    {
        if (!is_dir($path)) {
            return 0;
        }

        $size = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = min((int) floor(log($bytes, 1024)), count($units) - 1);
        $value = $bytes / (1024 ** $power);

        return sprintf($power === 0 ? '%.0f %s' : '%.1f %s', $value, $units[$power]);
    }
}
