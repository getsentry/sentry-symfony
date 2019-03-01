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
        $rootNode = \method_exists(TreeBuilder::class, 'getRootNode')
            ? $treeBuilder->getRootNode()
            : $treeBuilder->root('sentry');

        // Basic Sentry configuration
        $rootNode->children()
            ->scalarNode('dsn')
            ->defaultNull()
            ->beforeNormalization()
            ->ifString()
            ->then($this->getTrimClosure());

        // Options array (to be passed to Sentry\Options constructor) -- please keep alphabetical order!
        $optionsNode = $rootNode->children()
            ->arrayNode('options')
            ->addDefaultsIfNotSet();

        $defaultValues = new Options();
        $optionsChildNodes = $optionsNode->children();

        $optionsChildNodes->booleanNode('attach_stacktrace');
        $optionsChildNodes->variableNode('before_breadcrumb')
            ->validate()
            ->ifTrue($this->isNotAValidCallback())
            ->thenInvalid('Expecting callable or service reference, got %s');
        $optionsChildNodes->variableNode('before_send')
            ->validate()
            ->ifTrue($this->isNotAValidCallback())
            ->thenInvalid('Expecting callable or service reference, got %s');
        $optionsChildNodes->integerNode('context_lines')
            ->min(0)
            ->max(99);
        $optionsChildNodes->booleanNode('default_integrations');
        $optionsChildNodes->booleanNode('enable_compression');
        $optionsChildNodes->scalarNode('environment')
            ->defaultValue('%kernel.environment%')
            ->cannotBeEmpty();
        $optionsChildNodes->scalarNode('error_types');
        $optionsChildNodes->arrayNode('in_app_exclude')
            ->defaultValue([
                '%kernel.cache_dir%',
                $this->getProjectRoot() . '/vendor',
            ])
            ->prototype('scalar');
        $optionsChildNodes->arrayNode('excluded_exceptions')
            ->defaultValue($defaultValues->getExcludedExceptions())
            ->prototype('scalar');
        $optionsChildNodes->scalarNode('http_proxy');
        $optionsChildNodes->arrayNode('integrations')
            ->prototype('scalar')
            ->validate()
            ->ifTrue(function ($value): bool {
                if (! is_string($value)) {
                    return true;
                }

                return '@' !== substr($value, 0, 1);
            })
            ->thenInvalid('Expecting service reference, got %s');
        $optionsChildNodes->scalarNode('logger');
        $optionsChildNodes->integerNode('max_breadcrumbs')
            ->min(1);
        $optionsChildNodes->integerNode('max_value_length')
            ->min(1);
        $optionsChildNodes->arrayNode('prefixes')
            ->defaultValue($defaultValues->getPrefixes())
            ->prototype('scalar');
        $optionsChildNodes->scalarNode('project_root')
            ->defaultValue($this->getProjectRoot());
        $optionsChildNodes->scalarNode('release');
        $optionsChildNodes->floatNode('sample_rate')
            ->min(0.0)
            ->max(1.0);
        $optionsChildNodes->integerNode('send_attempts')
            ->min(1);
        $optionsChildNodes->booleanNode('send_default_pii');
        $optionsChildNodes->scalarNode('server_name');
        $optionsChildNodes->arrayNode('tags')
            ->normalizeKeys(false)
            ->prototype('scalar');

        // Bundle-specific configuration
        $listenerPriorities = $rootNode->children()
            ->arrayNode('listener_priorities')
            ->addDefaultsIfNotSet()
            ->children();
        $listenerPriorities->scalarNode('request')
            ->defaultValue(1);
        $listenerPriorities->scalarNode('console')
            ->defaultValue(1);

        return $treeBuilder;
    }

    private function getTrimClosure(): \Closure
    {
        return function ($str): ?string {
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
