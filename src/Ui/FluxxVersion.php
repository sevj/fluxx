<?php

declare(strict_types=1);

namespace Fluxx\Ui;

use Composer\InstalledVersions;

/**
 * Installed version of the Fluxx package, with its commit when known (e.g. "1.19.0 (d01bcee)").
 */
final class FluxxVersion
{
    public function current(): string
    {
        if (!class_exists(InstalledVersions::class) || !InstalledVersions::isInstalled('sevj/fluxx')) {
            return 'dev';
        }

        $prettyVersion = InstalledVersions::getPrettyVersion('sevj/fluxx') ?? 'dev';
        $reference = InstalledVersions::getReference('sevj/fluxx');

        if (is_string($reference) && $reference !== '') {
            return sprintf('%s (%s)', $prettyVersion, substr($reference, 0, 7));
        }

        return $prettyVersion;
    }
}
