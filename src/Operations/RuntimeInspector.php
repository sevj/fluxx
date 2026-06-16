<?php

declare(strict_types=1);

namespace Fluxx\Operations;

use Fluxx\Runtime\FluxxRuntimeSnapshotProvider;

class RuntimeInspector
{
    public function __construct(
        private readonly FluxxRuntimeSnapshotProvider $snapshotProvider,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return $this->snapshotProvider->snapshot();
    }
}
