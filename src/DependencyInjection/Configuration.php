<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\DependencyInjection;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Jean85\PrettyVersions;
use Sentry\Options;
use Sentry\SentryBundle\ErrorTypesParser;
use Sentry\Transport\TransportFactoryInterface;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Cache\CacheInterface;

final class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritdoc}
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('sentry');

        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = method_exists(TreeBuilder::class, 'getRootNode')
            ? $treeBuilder->getRootNode()
            : $treeBuilder->root('sentry');

        $rootNode
            ->children()
                ->scalarNode('dsn')
                    ->info('If this value is not provided, the SDK will try to read it from the SENTRY_DSN environment variable. If that variable also does not exist, the SDK will just not send any events.')
                ->end()
                ->booleanNode('register_error_listener')->defaultTrue()->end()
                ->scalarNode('transport_factory')
                    ->info('The service ID of the transport factory used by the default SDK client.')
                    ->defaultValue(TransportFactoryInterface::class)
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
                            ->defaultValue(array_merge(['%kernel.project_dir%'], array_filter(explode(\PATH_SEPARATOR, get_include_path() ?: ''))))
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
                        ->scalarNode('error_types')
                            ->beforeNormalization()
                                ->always(\Closure::fromCallable([ErrorTypesParser::class, 'parse']))
                            ->end()
                        ->end()
                        ->integerNode('max_breadcrumbs')
                            ->min(0)
                            ->max(Options::DEFAULT_MAX_BREADCRUMBS)
                        ->end()
                        ->variableNode('before_breadcrumb')->end()
                        ->arrayNode('in_app_exclude')
                            ->scalarPrototype()->end()
                            ->beforeNormalization()->castToArray()->end()
                            ->defaultValue([
                                '%kernel.cache_dir%',
                                '%kernel.build_dir%',
                                '%kernel.project_dir%/vendor',
                            ])
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
                            ->values([
                                'none',
                                'small',
                                'medium',
                                'always',
                            ])
                        ->end()
                        ->arrayNode('class_serializers')
                            ->useAttributeAsKey('class')
                            ->normalizeKeys(false)
                            ->scalarPrototype()->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        $this->addMessengerSection($rootNode);
        $this->addDistributedTracingSection($rootNode);

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

    private function addDistributedTracingSection(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->arrayNode('tracing')
                    ->canBeDisabled()
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('dbal')
                            ->{class_exists(DoctrineBundle::class) ? 'canBeDisabled' : 'canBeEnabled'}()
                            ->fixXmlConfig('connection')
                            ->children()
                                ->arrayNode('connections')
                                    ->scalarPrototype()->end()
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('twig')
                            ->{class_exists(TwigBundle::class) ? 'canBeDisabled' : 'canBeEnabled'}()
                        ->end()
                        ->arrayNode('cache')
                            ->{interface_exists(CacheInterface::class) ? 'canBeDisabled' : 'canBeEnabled'}()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }
}
