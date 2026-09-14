<?php

declare(strict_types=1);

namespace Fluxx\Settings;

final readonly class RuntimeSettings
{
    public function __construct(
        public int $staleLockTimeoutSeconds,
        public int $workerHeartbeatTimeoutSeconds,
        public int $healthWarningThresholdSeconds,
        public int $healthCriticalThresholdSeconds,
        public int $maxGlobalRetries,
    ) {
    }
}
