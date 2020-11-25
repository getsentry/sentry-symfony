<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\DependencyInjection;

use Jean85\PrettyVersions;
use Monolog\Logger as MonologLogger;
use Sentry\ClientInterface;
use Sentry\Monolog\Handler;
use Sentry\Options;
use Sentry\SentryBundle\EventListener\ErrorListener;
use Sentry\SentryBundle\EventListener\MessengerListener;
use Sentry\SentryBundle\SentryBundle;
use Sentry\Serializer\RepresentationSerializer;
use Sentry\Serializer\Serializer;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\ConfigurableExtension;

final class SentryExtension extends ConfigurableExtension
{
    /**
     * {@inheritdoc}
     */
    public function getXsdValidationBasePath()
    {
        return __DIR__ . '/../Resources/config/schema';
    }

    /**
     * {@inheritdoc}
     */
    public function getNamespace()
    {
        return 'https://sentry.io/schema/dic/sentry-symfony';
    }

    /**
     * @param array<string, mixed> $mergedConfig
     */
    protected function loadInternal(array $mergedConfig, ContainerBuilder $container): void
    {
        $loader = new Loader\XmlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.xml');

        foreach ($mergedConfig['listener_priorities'] as $key => $priority) {
            $container->setParameter('sentry.listener_priorities.' . $key, $priority);
        }

        $this->registerConfiguration($container, $mergedConfig);
        $this->registerErrorListenerConfiguration($container, $mergedConfig);
        $this->registerMessengerListenerConfiguration($container, $mergedConfig['messenger']);
        $this->registerMonologHandlerConfiguration($container, $mergedConfig['monolog']);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerConfiguration(ContainerBuilder $container, array $config): void
    {
        $options = $config['options'];

        if (isset($config['dsn'])) {
            $options['dsn'] = $config['dsn'];
        }

        if (isset($options['traces_sampler'])) {
            $options['traces_sampler'] = new Reference($options['traces_sampler']);
        }

        if (isset($options['before_send'])) {
            $options['before_send'] = new Reference($options['before_send']);
        }

        if (isset($options['before_breadcrumb'])) {
            $options['before_breadcrumb'] = new Reference($options['before_breadcrumb']);
        }

        if (isset($options['class_serializers'])) {
            $options['class_serializers'] = array_map(static function (string $value): Reference {
                return new Reference($value);
            }, $options['class_serializers']);
        }

        if (isset($options['integrations'])) {
            $options['integrations'] = array_map(static function (string $value): Reference {
                return new Reference($value);
            }, $options['integrations']);
        }

        $clientOptionsDefinition = $container->register('sentry.client.options', Options::class);
        $clientOptionsDefinition->setArgument(0, $options);

        $serializerDefinition = $container->register('sentry.client.serializer', Serializer::class);
        $serializerDefinition->setArgument(0, new Reference('sentry.client.options'));

        $representationSerializerDefinition = $container->register('sentry.client.representation_serializer', RepresentationSerializer::class);
        $representationSerializerDefinition->setArgument(0, new Reference('sentry.client.options'));

        $clientDefinition = $container->getDefinition(ClientInterface::class);
        $clientDefinition->setArgument(0, new Reference('sentry.client.options'));
        $clientDefinition->addMethodCall('setSerializer', [new Reference('sentry.client.serializer')]);
        $clientDefinition->addMethodCall('setRepresentationSerializer', [new Reference('sentry.client.representation_serializer')]);
        $clientDefinition->addMethodCall('setSdkIdentifier', [SentryBundle::SDK_IDENTIFIER]);
        $clientDefinition->addMethodCall('setSdkVersion', [PrettyVersions::getRootPackageVersion()->getPrettyVersion()]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerErrorListenerConfiguration(ContainerBuilder $container, array $config): void
    {
        if (! $config['register_error_listener']) {
            $container->removeDefinition(ErrorListener::class);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerMessengerListenerConfiguration(ContainerBuilder $container, array $config): void
    {
        if (! $config['enabled']) {
            $container->removeDefinition(MessengerListener::class);

            return;
        }

        $container->getDefinition(MessengerListener::class)->setArgument(1, $config['capture_soft_fails']);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerMonologHandlerConfiguration(ContainerBuilder $container, array $config): void
    {
        $errorHandlerConfig = $config['error_handler'];

        if (! $errorHandlerConfig['enabled']) {
            $container->removeDefinition(Handler::class);

            return;
        }

        if (! class_exists(MonologLogger::class)) {
            throw new LogicException(sprintf('To use the "%s" class you need to require the "monolog/monolog" package.', Handler::class));
        }

        $definition = $container->getDefinition(Handler::class);
        $definition->setArgument(0, MonologLogger::toMonologLevel($config['level']));
        $definition->setArgument(1, $config['bubble']);
    }
}
