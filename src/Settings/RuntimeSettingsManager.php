<?php

declare(strict_types=1);

namespace Fluxx\Settings;

use Fluxx\Repository\FluxxSettingLookupInterface;
use InvalidArgumentException;

use function is_int;

final readonly class RuntimeSettingsManager
{
    private const KEY = 'runtime_settings';

    public function __construct(
        private FluxxSettingLookupInterface $settingRepository,
        private int $defaultStaleLockTimeoutSeconds,
        private int $defaultWorkerHeartbeatTimeoutSeconds,
        private int $defaultHealthWarningThresholdSeconds,
        private int $defaultHealthCriticalThresholdSeconds,
        private int $defaultMaxGlobalRetries,
    ) {
    }

    public function get(): RuntimeSettings
    {
        $value = $this->settingRepository->findValue(self::KEY) ?? [];

        return new RuntimeSettings(
            staleLockTimeoutSeconds: $this->resolveInt($value, 'stale_lock_timeout_seconds', $this->defaultStaleLockTimeoutSeconds),
            workerHeartbeatTimeoutSeconds: $this->resolveInt($value, 'worker_heartbeat_timeout_seconds', $this->defaultWorkerHeartbeatTimeoutSeconds),
            healthWarningThresholdSeconds: $this->resolveInt($value, 'health_warning_threshold_seconds', $this->defaultHealthWarningThresholdSeconds),
            healthCriticalThresholdSeconds: $this->resolveInt($value, 'health_critical_threshold_seconds', $this->defaultHealthCriticalThresholdSeconds),
            maxGlobalRetries: $this->resolveInt($value, 'max_global_retries', $this->defaultMaxGlobalRetries, 0),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function save(array $data): RuntimeSettings
    {
        $value = [
            'stale_lock_timeout_seconds' => $this->validateInt($data, 'stale_lock_timeout_seconds', $this->defaultStaleLockTimeoutSeconds, 1, 86_400),
            'worker_heartbeat_timeout_seconds' => $this->validateInt($data, 'worker_heartbeat_timeout_seconds', $this->defaultWorkerHeartbeatTimeoutSeconds, 1, 3_600),
            'health_warning_threshold_seconds' => $this->validateInt($data, 'health_warning_threshold_seconds', $this->defaultHealthWarningThresholdSeconds, 1, 3_600),
            'health_critical_threshold_seconds' => $this->validateInt($data, 'health_critical_threshold_seconds', $this->defaultHealthCriticalThresholdSeconds, 1, 86_400),
            'max_global_retries' => $this->validateInt($data, 'max_global_retries', $this->defaultMaxGlobalRetries, 0, 100),
        ];

        if ($value['health_critical_threshold_seconds'] <= $value['health_warning_threshold_seconds']) {
            throw new InvalidArgumentException('The critical threshold must be greater than the warning threshold.');
        }

        $this->settingRepository->saveValue(self::KEY, $value);

        return $this->get();
    }

    /**
     * @param array<string, mixed> $value
     */
    private function resolveInt(array $value, string $key, int $default, int $min = 1): int
    {
        $raw = $value[$key] ?? null;

        if (!is_int($raw) || $raw < $min) {
            return $default;
        }

        return $raw;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validateInt(array $data, string $key, int $default, int $min, int $max): int
    {
        $raw = $data[$key] ?? null;

        if ($raw === null || $raw === '') {
            return $default;
        }

        $int = (int) $raw;

        if ($int < $min) {
            throw new InvalidArgumentException(sprintf('Value for "%s" must be at least %d.', $key, $min));
        }

        if ($int > $max) {
            throw new InvalidArgumentException(sprintf('Value for "%s" must not exceed %d.', $key, $max));
        }

        return $int;
    }
}
