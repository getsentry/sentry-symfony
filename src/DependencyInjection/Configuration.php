<?php

namespace Sentry\SentryBundle\DependencyInjection;

use Sentry\Options;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
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
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder('sentry');
        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = \method_exists(TreeBuilder::class, 'getRootNode') ? $treeBuilder->getRootNode() : $treeBuilder->root('sentry');

        // Basic Sentry configuration
        $rootNode->children()
            ->scalarNode('dsn')
                ->beforeNormalization()
                    ->ifString()
                    ->then($this->getTrimClosure())
                ->end()
                ->defaultNull()
            ->end();

        // Options array (to be passed to Sentry\Options constructor) -- please keep alphabetical order!
        $optionsNode = $rootNode->children()
            ->arrayNode('options')
            ->addDefaultsIfNotSet();

        $defaultValues = new Options();

        $optionsNode
            ->children()
            ->booleanNode('default_integrations')->end()
            ->arrayNode('excluded_exceptions')
                ->defaultValue($defaultValues->getExcludedExceptions())
                ->scalarPrototype()->end()
            ->end()
            // TODO -- integrations
            ->arrayNode('prefixes')
                ->defaultValue($defaultValues->getPrefixes())
                ->scalarPrototype()->end()
            ->end()
            ->scalarNode('project_root')
                ->defaultValue('%kernel.project_dir%')
            ->end()
            ->floatNode('sample_rate')
                ->min(0.0)
                ->max(1.0)
            ->end()
            ->integerNode('send_attempts')
                ->min(1)
            ->end()
        ;

        // Bundle-specific configuration
        $rootNode->children()
            ->arrayNode('listener_priorities')
                ->addDefaultsIfNotSet()
                ->children()
                    ->scalarNode('request')->defaultValue(1)->end()
                    ->scalarNode('console')->defaultValue(1)->end()
                ->end()
            ->end();

        return $treeBuilder;
    }

    private function getTrimClosure(): callable
    {
        return function ($str) {
            $value = trim($str);
            if ($value === '') {
                return null;
            }

            return $value;
        };
    }
}
