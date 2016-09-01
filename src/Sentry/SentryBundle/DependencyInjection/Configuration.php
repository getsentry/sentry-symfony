<?php

namespace Sentry\SentryBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * This is the class that validates and merges configuration from your app/config files
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/extension.html#cookbook-bundles-extension-config-class}
 */
class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritDoc}
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('sentry');

        $rootNode
            ->children()
                ->booleanNode('enabled')
                    ->defaultTrue()
                ->end()
                ->scalarNode('app_path')
                    ->defaultValue('%kernel.root_dir%')
                ->end()
                ->scalarNode('client')
                    ->defaultValue('Sentry\SentryBundle\SentrySymfonyClient')
                ->end()
                ->scalarNode('environment')
                    ->defaultValue('%kernel.environment%')
                ->end()
                ->scalarNode('dsn')
                    ->defaultNull()
                ->end()
                ->scalarNode('exception_listener')
                    ->defaultValue('Sentry\SentryBundle\EventListener\ExceptionListener')
                ->end()
                ->arrayNode('skip_capture')
                    ->treatNullLike(array())
                    ->prototype('scalar')->end()
                    ->defaultValue(array('Symfony\Component\HttpKernel\Exception\HttpExceptionInterface'))
                ->end()
                ->scalarNode('release')
                    ->defaultNull()
                ->end()
                ->arrayNode('prefixes')
                    ->prototype('scalar')->end()
                    ->treatNullLike(array())
                    ->defaultValue(array('%kernel.root_dir%/..'))
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
