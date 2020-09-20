<?php

namespace Sentry\SentryBundle\DependencyInjection;

use Jean85\PrettyVersions;
use Sentry\Options;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Messenger\MessageBusInterface;

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
    public function getConfigTreeBuilder(): TreeBuilder
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

        $rootNode->children()
            ->booleanNode('register_error_listener')
            ->defaultTrue();
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
        $optionsChildNodes->booleanNode('capture_silenced_errors');
        $optionsChildNodes->arrayNode('class_serializers')
            ->defaultValue([])
            ->prototype('scalar');
        $optionsChildNodes->integerNode('context_lines')
            ->min(0)
            ->max(99);
        $optionsChildNodes->booleanNode('default_integrations');
        $optionsChildNodes->booleanNode('enable_compression');
        $optionsChildNodes->scalarNode('environment')
            ->defaultValue('%kernel.environment%')
            ->cannotBeEmpty();
        $optionsChildNodes->scalarNode('error_types');
        $optionsChildNodes->arrayNode('in_app_include')
            ->defaultValue([])
            ->prototype('scalar');
        $optionsChildNodes->arrayNode('in_app_exclude')
            ->defaultValue([
                '%kernel.cache_dir%',
                '%kernel.project_dir%/vendor',
            ])
            ->prototype('scalar');
        $optionsChildNodes->arrayNode('excluded_exceptions')
            ->defaultValue([])
            ->prototype('scalar');
        $optionsChildNodes->scalarNode('http_proxy');
        $optionsChildNodes->arrayNode('integrations')
            ->prototype('scalar')
            ->validate()
            ->ifTrue(static function ($value): bool {
                if (! is_string($value) && '' != $value) {
                    return true;
                }

                return '@' !== $value[0];
            })
            ->thenInvalid('Expecting service reference, got "%s"');
        $optionsChildNodes->scalarNode('logger');
        $optionsChildNodes->enumNode('max_request_body_size')
            ->values([
                'none',
                'small',
                'medium',
                'always',
            ]);
        $optionsChildNodes->integerNode('max_breadcrumbs')
            ->min(1);
        $optionsChildNodes->integerNode('max_value_length')
            ->min(1);
        $optionsChildNodes->arrayNode('prefixes')
            ->defaultValue($defaultValues->getPrefixes())
            ->prototype('scalar');
        $optionsChildNodes->scalarNode('project_root');

        $optionsChildNodes->scalarNode('release')
            ->info('Release version to be reported to sentry, see https://docs.sentry.io/workflow/releases/?platform=php')
            ->example('my-application@ff11bb')
            ->beforeNormalization()
            ->ifString()
            ->then($this->escapeInvalidReleaseCharacters())
            ->end()
            ->defaultValue(PrettyVersions::getRootPackageVersion()->getPrettyVersion());

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
        $listenerPriorities->scalarNode('sub_request')
            ->defaultValue(1);
        $listenerPriorities->scalarNode('console')
            ->defaultValue(1);
        $listenerPriorities->scalarNode('request_error')
            ->defaultValue(128);
        $listenerPriorities->scalarNode('console_error')
            ->defaultValue(128);
        $listenerPriorities->scalarNode('worker_error')
            ->defaultValue(99);

        // Monolog handler configuration
        $monologConfiguration = $rootNode->children()
            ->arrayNode('monolog')
            ->addDefaultsIfNotSet()
            ->children();

        $errorHandler = $monologConfiguration
            ->arrayNode('error_handler')
            ->addDefaultsIfNotSet()
            ->children();
        $errorHandler->booleanNode('enabled')
            ->defaultFalse();
        $errorHandler->scalarNode('level')
            ->defaultValue('DEBUG')
            ->cannotBeEmpty();
        $errorHandler->booleanNode('bubble')
            ->defaultTrue();

        // Messenger configuration
        $messengerConfiguration = $rootNode->children()
            ->arrayNode('messenger')
            ->addDefaultsIfNotSet()
            ->children();

        $messengerConfiguration->booleanNode('enabled')
            ->defaultValue(interface_exists(MessageBusInterface::class));
        $messengerConfiguration->booleanNode('capture_soft_fails')
            ->defaultTrue();

        return $treeBuilder;
    }

    private function escapeInvalidReleaseCharacters(): \Closure
    {
        return static function ($str): string {
            return str_replace('/', '-', $str);
        };
    }

    private function getTrimClosure(): \Closure
    {
        return static function ($str): ?string {
            $value = trim($str);
            if ($value === '') {
                return null;
            }

            return $value;
        };
    }

    private function isNotAValidCallback(): \Closure
    {
        return static function ($value): bool {
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
