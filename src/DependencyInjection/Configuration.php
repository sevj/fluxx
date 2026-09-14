<?php

declare(strict_types=1);

namespace Fluxx\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('fluxx');

        $treeBuilder->getRootNode()
            ->children()
                ->arrayNode('security')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->info('When true, Fluxx prepends its own user provider, password hasher and role hierarchy into the security configuration. Set to false if the host application provides its own authentication.')
                            ->defaultValue(true)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('runtime')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('defaults')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->integerNode('stale_lock_timeout_seconds')
                                    ->info('Default staleness threshold (in seconds) for execution locks. A lock older than this threshold without a worker heartbeat is considered stale and recoverable. Can be overridden per-workflow and via the admin UI.')
                                    ->defaultValue(1800)
                                    ->min(1)
                                ->end()
                                ->integerNode('worker_heartbeat_timeout_seconds')
                                    ->info('Default threshold (in seconds) after which a worker is considered idle or offline if no heartbeat has been received.')
                                    ->defaultValue(120)
                                    ->min(1)
                                ->end()
                                ->integerNode('health_warning_threshold_seconds')
                                    ->info('Threshold (in seconds) for the system health warning state. Runs or workers exceeding this threshold trigger a warning.')
                                    ->defaultValue(60)
                                    ->min(1)
                                ->end()
                                ->integerNode('health_critical_threshold_seconds')
                                    ->info('Threshold (in seconds) for the system health critical state. Runs or workers exceeding this threshold trigger a critical alert.')
                                    ->defaultValue(300)
                                    ->min(1)
                                ->end()
                                ->integerNode('max_global_retries')
                                    ->info('Maximum number of retries allowed per step across all policies. Acts as a safety guard against infinite retry loops.')
                                    ->defaultValue(10)
                                    ->min(0)
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
