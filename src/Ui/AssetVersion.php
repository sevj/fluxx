<?php

declare(strict_types=1);

namespace Fluxx\Ui;

/**
 * Cache-busting version of the assets served by the bundle. They are sent with
 * an immutable cache header, so the version must change whenever one of them does.
 */
final class AssetVersion
{
    private ?string $version = null;

    public function __construct(
        private readonly string $packageDirectory = __DIR__ . '/../..',
    ) {
    }

    public function current(): string
    {
        return $this->version ??= $this->compute();
    }

    private function compute(): string
    {
        $files = array_filter(
            [
                ...(glob($this->packageDirectory . '/src/Resources/public/scripts/*.js') ?: []),
                ...(glob($this->packageDirectory . '/src/Resources/public/styles/*.css') ?: []),
                $this->packageDirectory . '/logo.png',
                $this->packageDirectory . '/favicon.png',
            ],
            is_file(...),
        );
        sort($files);

        $context = hash_init('xxh128');

        foreach ($files as $file) {
            hash_update_file($context, $file);
        }

        return substr(hash_final($context), 0, 12);
    }
}
