<?php

declare(strict_types=1);

namespace Fluxx\Twig;

use Fluxx\Ui\AssetVersion;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class AssetExtension extends AbstractExtension
{
    public function __construct(
        private readonly AssetVersion $assetVersion,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('fluxx_asset_version', $this->assetVersion->current(...)),
        ];
    }
}
