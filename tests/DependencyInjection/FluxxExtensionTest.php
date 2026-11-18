<?php

declare(strict_types=1);

namespace Fluxx\Tests\DependencyInjection;

use Fluxx\DependencyInjection\FluxxExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class FluxxExtensionTest extends TestCase
{
    #[Test]
    public function the_continuation_transport_is_disabled_by_default(): void
    {
        $container = $this->load([]);

        self::assertNull($container->getParameter('fluxx.runtime.continuation_transport_name'));
        self::assertSame('fluxx', $container->getParameter('fluxx.runtime.transport_name'));
    }

    #[Test]
    public function it_exposes_the_continuation_transport(): void
    {
        $container = $this->load(['runtime' => ['continuation_transport_name' => 'fluxx_continue']]);

        self::assertSame('fluxx_continue', $container->getParameter('fluxx.runtime.continuation_transport_name'));
    }

    #[Test]
    public function the_continuation_transport_must_differ_from_the_main_one(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->load(['runtime' => ['transport_name' => 'fluxx', 'continuation_transport_name' => 'fluxx']]);
    }

    #[Test]
    public function an_empty_continuation_transport_name_is_rejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->load(['runtime' => ['continuation_transport_name' => '']]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', sys_get_temp_dir());
        $container->setParameter('kernel.environment', 'test');
        (new FluxxExtension())->load([$config], $container);

        return $container;
    }
}
