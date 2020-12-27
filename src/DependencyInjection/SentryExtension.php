<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\DependencyInjection;

use Jean85\PrettyVersions;
use Monolog\Logger as MonologLogger;
use Sentry\Client;
use Sentry\ClientBuilder;
use Sentry\Integration\IgnoreErrorsIntegration;
use Sentry\Monolog\Handler;
use Sentry\Options;
use Sentry\SentryBundle\EventListener\ErrorListener;
use Sentry\SentryBundle\EventListener\MessengerListener;
use Sentry\SentryBundle\SentryBundle;
use Sentry\Serializer\RepresentationSerializer;
use Sentry\Serializer\Serializer;
use Sentry\Transport\TransportFactoryInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\ErrorHandler\Error\FatalError;
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

        if (! $container->hasParameter('kernel.build_dir')) {
            $options['in_app_exclude'] = array_filter($options['in_app_exclude'], static function (string $value): bool {
                return '%kernel.build_dir%' !== $value;
            });
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
            $options['integrations'] = $this->configureIntegrationsOption($options['integrations'], $config['register_error_listener']);
        }

        $container
            ->register('sentry.client.options', Options::class)
            ->setPublic(false)
            ->setArgument(0, $options);

        $serializer = (new Definition(Serializer::class))
            ->setPublic(false)
            ->setArgument(0, new Reference('sentry.client.options'));

        $representationSerializerDefinition = (new Definition(RepresentationSerializer::class))
            ->setPublic(false)
            ->setArgument(0, new Reference('sentry.client.options'));

        $clientBuilderDefinition = (new Definition(ClientBuilder::class))
            ->setArgument(0, new Reference('sentry.client.options'))
            ->addMethodCall('setSdkIdentifier', [SentryBundle::SDK_IDENTIFIER])
            ->addMethodCall('setSdkVersion', [PrettyVersions::getVersion('sentry/sentry-symfony')->getPrettyVersion()])
            ->addMethodCall('setTransportFactory', [new Reference(TransportFactoryInterface::class)])
            ->addMethodCall('setSerializer', [$serializer])
            ->addMethodCall('setRepresentationSerializer', [$representationSerializerDefinition]);

        $container
            ->setDefinition('sentry.client', new Definition(Client::class))
            ->setPublic(false)
            ->setFactory([$clientBuilderDefinition, 'getClient']);
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
            throw new LogicException(sprintf('To use the "%s" class you need to require the "symfony/monolog-bundle" package.', Handler::class));
        }

        $definition = $container->getDefinition(Handler::class);
        $definition->setArgument(0, MonologLogger::toMonologLevel($config['level']));
        $definition->setArgument(1, $config['bubble']);
    }

    /**
     * @param string[] $integrations
     *
     * @return array<Reference|Definition>
     */
    private function configureIntegrationsOption(array $integrations, bool $registerErrorListener): array
    {
        $existsIgnoreErrorsIntegration = in_array(IgnoreErrorsIntegration::class, $integrations, true);
        $integrations = array_map(static function (string $value): Reference {
            return new Reference($value);
        }, $integrations);

        if ($registerErrorListener && false === $existsIgnoreErrorsIntegration) {
            // Prepend this integration to the beginning of the array so that
            // we can save some performance by skipping the rest of the integrations
            // if the error must be ignored
            array_unshift($integrations, new Definition(IgnoreErrorsIntegration::class, [['ignore_exceptions' => [FatalError::class]]]));
        }

        return $integrations;
    }
}
