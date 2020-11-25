<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\DependencyInjection;

use Jean85\PrettyVersions;
use Monolog\Logger as MonologLogger;
use Sentry\Options;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritdoc}
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('sentry');

        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = \method_exists(TreeBuilder::class, 'getRootNode')
            ? $treeBuilder->getRootNode()
            : $treeBuilder->root('sentry');

        $rootNode
            ->children()
                ->scalarNode('dsn')
                    ->beforeNormalization()
                        ->ifTrue(static function ($value): bool {
                            return empty($value) || (is_string($value) && '' === trim($value));
                        })
                        ->thenUnset()
                    ->end()
                ->end()
                ->arrayNode('options')
                    ->addDefaultsIfNotSet()
                    ->fixXmlConfig('integration')
                    ->fixXmlConfig('tag')
                    ->fixXmlConfig('class_serializer')
                    ->fixXmlConfig('prefix', 'prefixes')
                    ->children()
                        ->arrayNode('integrations')
                            ->scalarPrototype()->end()
                        ->end()
                        ->booleanNode('default_integrations')->end()
                        ->integerNode('send_attempts')->min(0)->end()
                        ->arrayNode('prefixes')
                            ->scalarPrototype()->end()
                        ->end()
                        ->floatNode('sample_rate')
                            ->min(0.0)
                            ->max(1.0)
                            ->info('The sampling factor to apply to events. A value of 0 will deny sending any event, and a value of 1 will send all events.')
                        ->end()
                        ->floatNode('traces_sample_rate')
                            ->min(0.0)
                            ->max(1.0)
                            ->info('The sampling factor to apply to transactions. A value of 0 will deny sending any transaction, and a value of 1 will send all transactions.')
                        ->end()
                        ->scalarNode('traces_sampler')->end()
                        ->booleanNode('attach_stacktrace')->end()
                        ->integerNode('context_lines')->min(0)->end()
                        ->booleanNode('enable_compression')->end()
                        ->scalarNode('environment')
                            ->cannotBeEmpty()
                            ->defaultValue('%kernel.environment%')
                        ->end()
                        ->scalarNode('logger')->end()
                        ->scalarNode('release')
                            ->cannotBeEmpty()
                            ->defaultValue(PrettyVersions::getRootPackageVersion()->getPrettyVersion())
                        ->end()
                        ->scalarNode('server_name')->end()
                        ->scalarNode('before_send')->end()
                        ->arrayNode('tags')
                            ->useAttributeAsKey('name')
                            ->normalizeKeys(false)
                            ->scalarPrototype()->end()
                        ->end()
                        ->integerNode('error_types')->end()
                        ->integerNode('max_breadcrumbs')
                            ->min(0)
                            ->max(Options::DEFAULT_MAX_BREADCRUMBS)
                        ->end()
                        ->variableNode('before_breadcrumb')->end()
                        ->arrayNode('in_app_exclude')
                            ->scalarPrototype()->end()
                            ->beforeNormalization()->castToArray()->end()
                            ->defaultValue(['%kernel.cache_dir%', '%kernel.build_dir%', '%kernel.project_dir%/vendor'])
                        ->end()
                        ->arrayNode('in_app_include')
                            ->scalarPrototype()->end()
                            ->beforeNormalization()->castToArray()->end()
                        ->end()
                        ->booleanNode('send_default_pii')->end()
                        ->integerNode('max_value_length')->min(0)->end()
                        ->scalarNode('http_proxy')->end()
                        ->booleanNode('capture_silenced_errors')->end()
                        ->enumNode('max_request_body_size')
                            ->isRequired()
                            ->values(['none', 'small', 'medium', 'always'])
                        ->end()
                        ->arrayNode('class_serializers')
                            ->scalarPrototype()->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        $this->addMessengerSection($rootNode);
        $this->addMonologSection($rootNode);
        $this->addListenerSection($rootNode);

        return $treeBuilder;
    }

    private function addMessengerSection(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->arrayNode('messenger')
                    ->{interface_exists(MessageBusInterface::class) ? 'canBeDisabled' : 'canBeEnabled'}()
                    ->children()
                        ->booleanNode('capture_soft_fails')->defaultTrue()->end()
                    ->end()
                ->end()
            ->end();
    }

    private function addMonologSection(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->arrayNode('monolog')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('error_handler')
                            ->{class_exists(MonologLogger::class) ? 'canBeDisabled' : 'canBeEnabled'}()
                        ->end()
                        ->scalarNode('level')
                            ->defaultValue(MonologLogger::DEBUG)
                            ->cannotBeEmpty()
                        ->end()
                        ->booleanNode('bubble')->defaultTrue()->end()
                    ->end()
                ->end()
            ->end();
    }

    private function addListenerSection(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->booleanNode('register_error_listener')->defaultTrue()->end()
                ->arrayNode('listener_priorities')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('request')->defaultValue(1)->end()
                        ->scalarNode('sub_request')->defaultValue(1)->end()
                        ->scalarNode('console')->defaultValue(128)->end()
                        ->scalarNode('request_error')->defaultValue(128)->end()
                        ->scalarNode('console_error')->defaultValue(-64)->end()
                        ->scalarNode('console_terminate')->defaultValue(-64)->end()
                        ->scalarNode('worker_error')->defaultValue(128)->end()
                    ->end()
                ->end()
            ->end();
    }
}
