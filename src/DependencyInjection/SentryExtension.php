<?php

namespace Sentry\SentryBundle\DependencyInjection;

use Sentry\ClientBuilderInterface;
use Sentry\Options;
use Sentry\SentryBundle\ErrorTypesParser;
use Sentry\SentryBundle\SentryBundle;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\DependencyInjection\Reference;
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
        $processedConfiguration = $this->processConfiguration($configuration, $configs);
        $loader = new Loader\XmlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.xml');

        $this->passConfigurationToOptions($container, $processedConfiguration);

        $container->getDefinition(ClientBuilderInterface::class)
            ->addMethodCall('setSdkIdentifier', [SentryBundle::SDK_IDENTIFIER])
            ->addMethodCall('setSdkVersion', [SentryBundle::getSdkVersion()]);

        foreach ($processedConfiguration['listener_priorities'] as $key => $priority) {
            $container->setParameter('sentry.listener_priorities.' . $key, $priority);
        }
    }

    private function passConfigurationToOptions(ContainerBuilder $container, array $processedConfiguration): void
    {
        $options = $container->getDefinition(Options::class);
        $options->addArgument(['dsn' => $processedConfiguration['dsn']]);

        $processedOptions = $processedConfiguration['options'];
        $mappableOptions = [
            'attach_stacktrace',
            'context_lines',
            'default_integrations',
            'enable_compression',
            'environment',
            'excluded_exceptions',
            'http_proxy',
            'logger',
            'max_breadcrumbs',
            'prefixes',
            'project_root',
            'release',
            'sample_rate',
            'send_attempts',
            'send_default_pii',
            'server_name',
            'tags',
        ];

        foreach ($mappableOptions as $optionName) {
            if (\array_key_exists($optionName, $processedOptions)) {
                $setterMethod = 'set' . str_replace('_', '', ucwords($optionName, '_'));
                $options->addMethodCall($setterMethod, [$processedOptions[$optionName]]);
            }
        }

        if (\array_key_exists('in_app_exclude', $processedOptions)) {
            $options->addMethodCall('setInAppExcludedPaths', [$processedOptions['in_app_exclude']]);
        }

        if (\array_key_exists('error_types', $processedOptions)) {
            $parsedValue = (new ErrorTypesParser($processedOptions['error_types']))->parse();
            $options->addMethodCall('setErrorTypes', [$parsedValue]);
        }

        if (\array_key_exists('before_send', $processedOptions)) {
            $optionValue = $processedOptions['before_send'];
            if (is_string($optionValue) && 0 === strpos($optionValue, '@')) {
                $beforeSend = new Reference(substr($optionValue, 1));
            } else {
                $beforeSend = $optionValue;
            }

            $options->addMethodCall('setBeforeSendCallback', [$beforeSend]);
        }
    }
}
