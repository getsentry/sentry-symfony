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
                ->scalarNode('app_path')
                    ->defaultValue('%kernel.root_dir%/..')
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
                ->arrayNode('options')
                    ->treatNullLike(array())
                    ->prototype('scalar')->end()
                    ->defaultValue(array())
                ->end()
                ->scalarNode('error_types')
                    ->defaultNull()
                ->end()
                ->scalarNode('exception_listener')
                    ->defaultValue('Sentry\SentryBundle\EventListener\ExceptionListener')
                    ->validate()
                    ->ifTrue($this->getExceptionListenerInvalidationClosure())
                        ->thenInvalid('The "sentry.exception_listener" parameter should be a FQCN of a class implementing the SentryExceptionListenerInterface interface')
                    ->end()
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
                ->arrayNode('excluded_app_paths')
                    ->prototype('scalar')->end()
                    ->treatNullLike(array())
                    ->defaultValue(array(
                        '%kernel.root_dir%/../vendor',
                        '%kernel.root_dir%/../app/cache',
                        '%kernel.root_dir%/../var/cache',
                    ))
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }

    /**
     * @return \Closure
     */
    private function getExceptionListenerInvalidationClosure()
    {
        return function ($value) {
            $implements = class_implements($value);
            if ($implements === false) {
                return true;
            }
            return !in_array('Sentry\SentryBundle\EventListener\SentryExceptionListenerInterface', $implements, true);
        };
    }
}
