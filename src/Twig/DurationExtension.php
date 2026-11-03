<?php

declare(strict_types=1);

namespace Fluxx\Twig;

use Fluxx\Ui\DurationFormatter;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class DurationExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('fluxx_duration', [DurationFormatter::class, 'format']),
        ];
    }
}
