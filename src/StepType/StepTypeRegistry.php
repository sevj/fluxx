<?php

declare(strict_types=1);

namespace Fluxx\StepType;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class StepTypeRegistry
{
    /**
     * @var array<string, StepTypeDefinition>
     */
    private array $types = [];

    /**
     * @param iterable<StepTypeProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator('fluxx.step_type_provider')]
        iterable $providers,
    ) {
        foreach ($providers as $provider) {
            foreach ($provider->stepTypes() as $stepType) {
                $this->types[$stepType->code()] = $stepType;
            }
        }
    }

    public function get(string $code): StepTypeDefinition
    {
        return $this->types[$code] ?? new StepTypeDefinition(
            code: $code,
            label: $this->humanize($code),
            tone: 'custom',
        );
    }

    private function humanize(string $code): string
    {
        $normalized = preg_replace('/[_\-]+/', ' ', trim($code));

        return ucwords((string) $normalized);
    }
}
