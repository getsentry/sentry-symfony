<?php

namespace Sentry\SentryBundle\DependencyInjection;

use Sentry\ClientBuilderInterface;
use Sentry\Options;
use Sentry\SentryBundle\SentryBundle;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

/**
 * This is the class that loads and manages your bundle configuration
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/extension.html}
 */
class SentryExtension extends Extension
{
    /**
     * {@inheritDoc}
     *
     * @throws InvalidConfigurationException
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);
        $loader = new Loader\XmlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.xml');

        $arrayOptions = $config['options'];
        $arrayOptions['dsn'] = $config['dsn'];
        $container->getDefinition(Options::class)
            ->addArgument($arrayOptions);

        $container->getDefinition(ClientBuilderInterface::class)
            ->addMethodCall('setSdkIdentifier', [SentryBundle::SDK_IDENTIFIER])
            ->addMethodCall('setSdkVersion', [SentryBundle::getSdkVersion()]);

        foreach ($config['listener_priorities'] as $key => $priority) {
            $container->setParameter('sentry.listener_priorities.' . $key, $priority);
        }
    }
}
