<?php

namespace Sentry\SentryBundle\DependencyInjection;

use Sentry\Options;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\HttpKernel\Kernel;

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
            ->booleanNode('attach_stacktrace')->end()
            // TODO -- before_breadcrumb
            // TODO -- before_send
            ->booleanNode('default_integrations')->end()
            ->integerNode('context_lines')
                ->min(0)
                ->max(99)
            ->end()
            ->booleanNode('enable_compression')->end()
            ->scalarNode('environment')
                ->defaultValue('%kernel.environment%')
                ->cannotBeEmpty()
            ->end()
            ->scalarNode('error_types')
            ->end()
            ->arrayNode('in_app_exclude')
                ->defaultValue($defaultValues->getInAppExcludedPaths())
                ->scalarPrototype()->end()
            ->end()
            ->arrayNode('excluded_exceptions')
                ->defaultValue($defaultValues->getExcludedExceptions())
                ->scalarPrototype()->end()
            ->end()
            // TODO -- integrations
            ->scalarNode('logger')
            ->end()
            ->integerNode('max_breadcrumbs')
                ->min(1)
            ->end()
            ->arrayNode('prefixes')
                ->defaultValue($defaultValues->getPrefixes())
                ->scalarPrototype()->end()
            ->end()
            ->scalarNode('project_root')
                ->defaultValue($this->getProjectRoot())
            ->end()
            ->scalarNode('release')
            ->end()
            ->floatNode('sample_rate')
                ->min(0.0)
                ->max(1.0)
            ->end()
            ->integerNode('send_attempts')
                ->min(1)
            ->end()
            ->booleanNode('send_default_pii')->end()
            ->scalarNode('server_name')
            ->end()
            ->arrayNode('tags')
                ->normalizeKeys(false)
                ->scalarPrototype()
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

    private function getProjectRoot(): string
    {
        if (method_exists(Kernel::class, 'getProjectDir')) {
            return '%kernel.project_dir%';
        }

        return '%kernel.root_dir%/..';
    }
}
