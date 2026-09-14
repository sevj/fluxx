<?php

declare(strict_types=1);

namespace Fluxx\Repository;

interface FluxxSettingLookupInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function findValue(string $key): ?array;

    /**
     * @param array<string, mixed> $value
     */
    public function saveValue(string $key, array $value): void;
}
