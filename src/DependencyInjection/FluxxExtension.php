<?php

declare(strict_types=1);

namespace Fluxx\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class FluxxExtension extends Extension implements PrependExtensionInterface
{
    public function prepend(ContainerBuilder $container): void
    {
        if ($container->hasExtension('doctrine')) {
            $container->prependExtensionConfig('doctrine', [
                'orm' => [
                    'mappings' => [
                        'Fluxx' => [
                            'type' => 'attribute',
                            'is_bundle' => false,
                            'dir' => __DIR__ . '/../../src/Entity',
                            'prefix' => 'Fluxx\\Entity',
                            'alias' => 'Fluxx',
                        ],
                    ],
                ],
            ]);
        }

        if ($container->hasExtension('twig')) {
            $container->prependExtensionConfig('twig', [
                'paths' => [
                    __DIR__ . '/../../templates' => 'Fluxx',
                ],
            ]);
        }

        if ($container->hasExtension('framework')) {
            $container->prependExtensionConfig('framework', [
                'translator' => [
                    'paths' => [
                        __DIR__ . '/../../translations',
                    ],
                ],
            ]);
        }

        $config = $this->resolveFluxxConfig($container);

        if (($config['security']['enabled'] ?? true) && $container->hasExtension('security')) {
            $container->prependExtensionConfig('security', [
                'password_hashers' => [
                    'Fluxx\\Entity\\User' => 'auto',
                ],
                'role_hierarchy' => [
                    'ROLE_ADMIN' => ['ROLE_FLUXX_USER'],
                ],
                'providers' => [
                    'fluxx_users' => [
                        'entity' => [
                            'class' => 'Fluxx\\Entity\\User',
                            'property' => 'email',
                        ],
                    ],
                ],
            ]);
        }
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('fluxx.security.enabled', $config['security']['enabled']);
        $container->setParameter('fluxx.runtime.defaults.stale_lock_timeout_seconds', $config['runtime']['defaults']['stale_lock_timeout_seconds']);
        $container->setParameter('fluxx.runtime.defaults.worker_heartbeat_timeout_seconds', $config['runtime']['defaults']['worker_heartbeat_timeout_seconds']);
        $container->setParameter('fluxx.runtime.defaults.health_warning_threshold_seconds', $config['runtime']['defaults']['health_warning_threshold_seconds']);
        $container->setParameter('fluxx.runtime.defaults.health_critical_threshold_seconds', $config['runtime']['defaults']['health_critical_threshold_seconds']);
        $container->setParameter('fluxx.runtime.defaults.max_global_retries', $config['runtime']['defaults']['max_global_retries']);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.yaml');
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveFluxxConfig(ContainerBuilder $container): array
    {
        $configs = $container->getExtensionConfig($this->getAlias());

        try {
            return $this->processConfiguration(new Configuration(), $configs);
        } catch (\Throwable) {
            return [];
        }
    }
}
