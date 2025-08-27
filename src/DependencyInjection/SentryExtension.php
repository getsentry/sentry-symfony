<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\DependencyInjection;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Jean85\PrettyVersions;
use Psr\Log\NullLogger;
use Sentry\Client;
use Sentry\ClientBuilder;
use Sentry\Integration\IntegrationInterface;
use Sentry\Integration\RequestFetcherInterface;
use Sentry\Integration\RequestIntegration;
use Sentry\Options;
use Sentry\SentryBundle\EventListener\ConsoleListener;
use Sentry\SentryBundle\EventListener\ErrorListener;
use Sentry\SentryBundle\EventListener\LoginListener;
use Sentry\SentryBundle\EventListener\MessengerListener;
use Sentry\SentryBundle\EventListener\TracingConsoleListener;
use Sentry\SentryBundle\EventListener\TracingRequestListener;
use Sentry\SentryBundle\EventListener\TracingSubRequestListener;
use Sentry\SentryBundle\Integration\IntegrationConfigurator;
use Sentry\SentryBundle\SentryBundle;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\ConnectionConfigurator;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriverMiddleware;
use Sentry\SentryBundle\Tracing\Twig\TwigTracingExtension;
use Sentry\Serializer\RepresentationSerializer;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpKernel\DependencyInjection\ConfigurableExtension;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class SentryExtension extends ConfigurableExtension
{
    /**
     * {@inheritdoc}
     */
    public function getXsdValidationBasePath(): string
    {
        return __DIR__ . '/../Resources/config/schema';
    }

    /**
     * {@inheritdoc}
     */
    public function getNamespace(): string
    {
        return 'https://sentry.io/schema/dic/sentry-symfony';
    }

    /**
     * {@inheritdoc}
     *
     * @param array<array-key, mixed> $mergedConfig
     */
    protected function loadInternal(array $mergedConfig, ContainerBuilder $container): void
    {
        $loader = new Loader\XmlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.xml');

        if (!$container->hasParameter('env(SENTRY_RELEASE)')) {
            $container->setParameter('env(SENTRY_RELEASE)', PrettyVersions::getRootPackageVersion()->getPrettyVersion());
        }

        $this->registerConfiguration($container, $mergedConfig);
        $this->registerErrorListenerConfiguration($container, $mergedConfig);
        $this->registerMessengerListenerConfiguration($container, $mergedConfig['messenger']);
        $this->registerTracingConfiguration($container, $mergedConfig['tracing']);
        $this->registerDbalTracingConfiguration($container, $mergedConfig['tracing']);
        $this->registerTwigTracingConfiguration($container, $mergedConfig['tracing']);
        $this->registerCacheTracingConfiguration($container, $mergedConfig['tracing']);
        $this->registerHttpClientTracingConfiguration($container, $mergedConfig['tracing']);

        if (!interface_exists(TokenStorageInterface::class)) {
            $container->removeDefinition(LoginListener::class);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerConfiguration(ContainerBuilder $container, array $config): void
    {
        $options = $config['options'];

        if (\array_key_exists('dsn', $config)) {
            $options['dsn'] = $config['dsn'];
        }

        if (!$container->hasParameter('kernel.build_dir')) {
            $options['in_app_exclude'] = array_filter($options['in_app_exclude'], static function (string $value): bool {
                return '%kernel.build_dir%' !== $value;
            });
        }

        if (isset($options['logger'])) {
            $options['logger'] = new Reference($options['logger']);
        }

        if (isset($options['traces_sampler'])) {
            $options['traces_sampler'] = new Reference($options['traces_sampler']);
        }

        if (isset($options['before_send'])) {
            $options['before_send'] = new Reference($options['before_send']);
        }

        if (isset($options['before_send_transaction'])) {
            $options['before_send_transaction'] = new Reference($options['before_send_transaction']);
        }

        if (isset($options['before_send_check_in'])) {
            $options['before_send_check_in'] = new Reference($options['before_send_check_in']);
        }

        if (isset($options['before_send_metrics'])) {
            $options['before_send_metrics'] = new Reference($options['before_send_metrics']);
        }

        if (isset($options['before_send_logs'])) {
            $options['before_send_logs'] = new Reference($options['before_send_logs']);
        }

        if (isset($options['before_breadcrumb'])) {
            $options['before_breadcrumb'] = new Reference($options['before_breadcrumb']);
        }

        if (isset($options['class_serializers'])) {
            $options['class_serializers'] = array_map(static function (string $value): Reference {
                return new Reference($value);
            }, $options['class_serializers']);
        }

        if (isset($options['transport'])) {
            $options['transport'] = new Reference($options['transport']);
        }

        if (isset($options['http_client'])) {
            $options['http_client'] = new Reference($options['http_client']);
        }

        $container->getDefinition(IntegrationConfigurator::class)
            ->setArgument(0, $this->configureIntegrationsOption($options['integrations'], $config))
            ->setArgument(1, $config['register_error_handler']);
        $options['integrations'] = new Reference(IntegrationConfigurator::class);

        $container
            ->register('sentry.client.options', Options::class)
            ->setPublic(false)
            ->setArgument(0, $options);

        $representationSerializerDefinition = (new Definition(RepresentationSerializer::class))
            ->setPublic(false)
            ->setArgument(0, new Reference('sentry.client.options'));

        $loggerReference = null === $config['logger']
            ? new Reference(NullLogger::class, ContainerBuilder::IGNORE_ON_INVALID_REFERENCE)
            : new Reference($config['logger']);

        $clientBuilderDefinition = (new Definition(ClientBuilder::class))
            ->setArgument(0, new Reference('sentry.client.options'))
            ->addMethodCall('setSdkIdentifier', [SentryBundle::SDK_IDENTIFIER])
            ->addMethodCall('setSdkVersion', [SentryBundle::SDK_VERSION])
            ->addMethodCall('setRepresentationSerializer', [$representationSerializerDefinition])
            ->addMethodCall('setLogger', [$loggerReference]);

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
        if (!$config['register_error_listener']) {
            $container->removeDefinition(ErrorListener::class);
        }

        $container->getDefinition(ConsoleListener::class)->setArgument(1, $config['register_error_listener']);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerMessengerListenerConfiguration(ContainerBuilder $container, array $config): void
    {
        if (!$this->isConfigEnabled($container, $config)) {
            $container->removeDefinition(MessengerListener::class);

            return;
        }

        $container->getDefinition(MessengerListener::class)->setArgument(1, $config['capture_soft_fails']);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerTracingConfiguration(ContainerBuilder $container, array $config): void
    {
        $container->setParameter('sentry.tracing.enabled', $config['enabled']);

        if (!$this->isConfigEnabled($container, $config)) {
            $container->removeDefinition(TracingRequestListener::class);
            $container->removeDefinition(TracingSubRequestListener::class);
            $container->removeDefinition(TracingConsoleListener::class);

            return;
        }

        $container->getDefinition(TracingConsoleListener::class)->replaceArgument(1, $config['console']['excluded_commands']);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerDbalTracingConfiguration(ContainerBuilder $container, array $config): void
    {
        $isConfigEnabled = $this->isConfigEnabled($container, $config)
            && $this->isConfigEnabled($container, $config['dbal']);

        if ($isConfigEnabled && !class_exists(DoctrineBundle::class)) {
            throw new \LogicException('DBAL tracing support cannot be enabled because the doctrine/doctrine-bundle Composer package is not installed.');
        }

        $container->setParameter('sentry.tracing.dbal.enabled', $isConfigEnabled);
        $container->setParameter('sentry.tracing.dbal.connections', $isConfigEnabled ? $config['dbal']['connections'] : []);

        if (!$isConfigEnabled) {
            $container->removeDefinition(ConnectionConfigurator::class);
            $container->removeDefinition(TracingDriverMiddleware::class);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerTwigTracingConfiguration(ContainerBuilder $container, array $config): void
    {
        $isConfigEnabled = $this->isConfigEnabled($container, $config)
            && $this->isConfigEnabled($container, $config['twig']);

        if ($isConfigEnabled && !class_exists(TwigBundle::class)) {
            throw new \LogicException('Twig tracing support cannot be enabled because the symfony/twig-bundle Composer package is not installed.');
        }

        if (!$isConfigEnabled) {
            $container->removeDefinition(TwigTracingExtension::class);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerCacheTracingConfiguration(ContainerBuilder $container, array $config): void
    {
        $isConfigEnabled = $this->isConfigEnabled($container, $config)
            && $this->isConfigEnabled($container, $config['cache']);

        if ($isConfigEnabled && !class_exists(CacheItem::class)) {
            throw new \LogicException('Cache tracing support cannot be enabled because the symfony/cache Composer package is not installed.');
        }

        $container->setParameter('sentry.tracing.cache.enabled', $isConfigEnabled);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerHttpClientTracingConfiguration(ContainerBuilder $container, array $config): void
    {
        $isConfigEnabled = $this->isConfigEnabled($container, $config)
            && $this->isConfigEnabled($container, $config['http_client']);

        if ($isConfigEnabled && !class_exists(HttpClient::class)) {
            throw new \LogicException('Http client tracing support cannot be enabled because the symfony/http-client Composer package is not installed.');
        }

        $container->setParameter('sentry.tracing.http_client.enabled', $isConfigEnabled);
    }

    /**
     * @param string[]             $integrations
     * @param array<string, mixed> $config
     *
     * @return array<Reference|Definition>
     */
    private function configureIntegrationsOption(array $integrations, array $config): array
    {
        $integrations = array_map(static function (string $value): Reference {
            return new Reference($value);
        }, $integrations);

        $integrations = $this->configureRequestIntegration($integrations, $config['options']['default_integrations'] ?? true);

        return $integrations;
    }

    /**
     * @param array<Reference|Definition> $integrations
     *
     * @return array<Reference|Definition>
     */
    private function configureRequestIntegration(array $integrations, bool $useDefaultIntegrations): array
    {
        if ($useDefaultIntegrations && !$this->isIntegrationEnabled(RequestIntegration::class, $integrations)) {
            $integrations[] = new Definition(RequestIntegration::class, [new Reference(RequestFetcherInterface::class)]);
        }

        return $integrations;
    }

    /**
     * @param class-string<IntegrationInterface> $integrationClass
     * @param array<Reference|Definition>        $integrations
     */
    private function isIntegrationEnabled(string $integrationClass, array $integrations): bool
    {
        foreach ($integrations as $integration) {
            if ($integration instanceof Reference && $integrationClass === (string) $integration) {
                return true;
            }

            if ($integration instanceof Definition && $integrationClass === $integration->getClass()) {
                return true;
            }
        }

        return false;
    }
}
