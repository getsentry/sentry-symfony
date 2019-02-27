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
            ->variableNode('before_breadcrumb')
                ->validate()
                    ->ifTrue($this->isNotAValidCallback())
                    ->thenInvalid('Expecting callable or service reference, got %s')
                ->end()
            ->end()
            ->variableNode('before_send')
                ->validate()
                    ->ifTrue($this->isNotAValidCallback())
                    ->thenInvalid('Expecting callable or service reference, got %s')
                ->end()
            ->end()
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
                ->defaultValue([
                    '%kernel.cache_dir%',
                    $this->getProjectRoot() . '/vendor',
                ])
                ->prototype('scalar')->end()
            ->end()
            ->arrayNode('excluded_exceptions')
                ->defaultValue($defaultValues->getExcludedExceptions())
                ->prototype('scalar')->end()
            ->end()
            ->scalarNode('http_proxy')
            ->end()
            ->arrayNode('integrations')
                ->prototype('scalar')
                    ->validate()
                        ->ifTrue(function ($value): bool {
                            if (! is_string($value)) {
                                return true;
                            }

                            return '@' !== substr($value, 0, 1);
                        })
                    ->thenInvalid('Expecting service reference, got %s')
                    ->end()
                ->end()
            ->end()
            ->scalarNode('logger')
            ->end()
            ->integerNode('max_breadcrumbs')
                ->min(1)
            ->end()
            ->integerNode('max_value_length')
                ->min(1)
            ->end()
            ->arrayNode('prefixes')
                ->defaultValue($defaultValues->getPrefixes())
                ->prototype('scalar')->end()
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
                ->prototype('scalar')
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

    private function isNotAValidCallback(): \Closure
    {
        return function ($value): bool {
            if (is_callable($value)) {
                return false;
            }

            if (is_string($value) && 0 === strpos($value, '@')) {
                return false;
            }

            return true;
        };
    }
}
