<?php

declare(strict_types=1);

namespace Fluxx\StepType;

final readonly class StepTypeDefinition
{
    public function __construct(
        private string $code,
        private string $label,
        private string $tone = 'custom',
    ) {
    }

    public function code(): string
    {
        return $this->code;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function tone(): string
    {
        return $this->tone;
    }

    public function toneClass(): string
    {
        return $this->isHexColor($this->tone) ? 'custom' : $this->tone;
    }

    public function toneStyle(): ?string
    {
        if (!$this->isHexColor($this->tone)) {
            return null;
        }

        return sprintf(
            '--step-tone-text: %1$s; --step-tone-bg: color-mix(in srgb, %1$s 16%%, transparent);',
            $this->tone,
        );
    }

    private function isHexColor(string $value): bool
    {
        return preg_match('/^#(?:[0-9a-fA-F]{3}){1,2}$/', $value) === 1;
    }
}
