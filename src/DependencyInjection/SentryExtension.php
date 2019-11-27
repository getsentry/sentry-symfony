<?php

namespace Sentry\SentryBundle\DependencyInjection;

use Monolog\Logger as MonologLogger;
use Sentry\ClientBuilderInterface;
use Sentry\Monolog\Handler;
use Sentry\Options;
use Sentry\SentryBundle\Command\SentryTestCommand;
use Sentry\SentryBundle\ErrorTypesParser;
use Sentry\SentryBundle\EventListener\ConsoleListener;
use Sentry\SentryBundle\EventListener\ErrorListener;
use Sentry\SentryBundle\EventListener\RequestListener;
use Sentry\SentryBundle\EventListener\SubRequestListener;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpKernel\KernelEvents;

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
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $processedConfiguration = $this->processConfiguration($configuration, $configs);
        $loader = new Loader\XmlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.xml');

        $this->passConfigurationToOptions($container, $processedConfiguration);

        $container->getDefinition(ClientBuilderInterface::class)
            ->setConfigurator([ClientBuilderConfigurator::class, 'configure']);

        foreach ($processedConfiguration['listener_priorities'] as $key => $priority) {
            $container->setParameter('sentry.listener_priorities.' . $key, $priority);
        }

        $this->configureErrorListener($container, $processedConfiguration);
        $this->setLegacyVisibilities($container);
        $this->configureMonologHandler($container, $processedConfiguration['monolog']);
    }

    private function passConfigurationToOptions(ContainerBuilder $container, array $processedConfiguration): void
    {
        $options = $container->getDefinition(Options::class);
        $options->addArgument(['dsn' => $processedConfiguration['dsn']]);

        $processedOptions = $processedConfiguration['options'];
        $mappableOptions = [
            'attach_stacktrace',
            'capture_silenced_errors',
            'context_lines',
            'default_integrations',
            'enable_compression',
            'environment',
            'excluded_exceptions',
            'http_proxy',
            'logger',
            'max_request_body_size',
            'max_breadcrumbs',
            'max_value_length',
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
            $beforeSendCallable = $this->valueToCallable($processedOptions['before_send']);
            $options->addMethodCall('setBeforeSendCallback', [$beforeSendCallable]);
        }

        if (\array_key_exists('before_breadcrumb', $processedOptions)) {
            $beforeBreadcrumbCallable = $this->valueToCallable($processedOptions['before_breadcrumb']);
            $options->addMethodCall('setBeforeBreadcrumbCallback', [$beforeBreadcrumbCallable]);
        }

        if (\array_key_exists('class_serializers', $processedOptions)) {
            $classSerializers = [];
            foreach ($processedOptions['class_serializers'] as $class => $serializer) {
                $classSerializers[$class] = $this->valueToCallable($serializer);
            }

            $options->addMethodCall('setClassSerializers', [$classSerializers]);
        }

        if (\array_key_exists('integrations', $processedOptions)) {
            $integrations = [];
            foreach ($processedOptions['integrations'] as $integrationName) {
                $integrations[] = new Reference(substr($integrationName, 1));
            }

            $options->addMethodCall('setIntegrations', [$integrations]);
        }
    }

    private function valueToCallable($value)
    {
        if (is_string($value) && 0 === strpos($value, '@')) {
            return new Reference(substr($value, 1));
        }

        return $value;
    }

    private function configureErrorListener(ContainerBuilder $container, array $processedConfiguration): void
    {
        if (! $processedConfiguration['register_error_listener']) {
            $container->removeDefinition(ErrorListener::class);

            return;
        }

        $this->tagExceptionListener($container);
        $this->tagConsoleErrorListener($container);
    }

    /**
     * BC layer for Symfony < 4.3
     */
    private function tagExceptionListener(ContainerBuilder $container): void
    {
        $listener = $container->getDefinition(ErrorListener::class);
        $method = class_exists(ExceptionEvent::class)
            ? 'onException'
            : 'onKernelException';

        $tagAttributes = [
            'event' => KernelEvents::EXCEPTION,
            'method' => $method,
            'priority' => '%sentry.listener_priorities.request_error%',
        ];

        $listener->addTag('kernel.event_listener', $tagAttributes);
    }

    /**
     * BC layer for Symfony < 3.3; see https://symfony.com/blog/new-in-symfony-3-3-better-handling-of-command-exceptions
     */
    private function tagConsoleErrorListener(ContainerBuilder $container): void
    {
        $listener = $container->getDefinition(ErrorListener::class);

        if (class_exists(ConsoleErrorEvent::class)) {
            $tagAttributes = [
                'event' => ConsoleEvents::ERROR,
                'method' => 'onConsoleError',
                'priority' => '%sentry.listener_priorities.console_error%',
            ];
        } else {
            $tagAttributes = [
                'event' => ConsoleEvents::EXCEPTION,
                'method' => 'onConsoleException',
                'priority' => '%sentry.listener_priorities.console_error%',
            ];
        }

        $listener->addTag('kernel.event_listener', $tagAttributes);
    }

    /**
     * BC layer for symfony < 3.3, listeners and commands must be public
     */
    private function setLegacyVisibilities(ContainerBuilder $container): void
    {
        if (Kernel::VERSION_ID < 30300) {
            $container->getDefinition(SentryTestCommand::class)->setPublic(true);
            $container->getDefinition(ConsoleListener::class)->setPublic(true);
            $container->getDefinition(RequestListener::class)->setPublic(true);
            $container->getDefinition(SubRequestListener::class)->setPublic(true);

            if ($container->hasDefinition(ErrorListener::class)) {
                $container->getDefinition(ErrorListener::class)->setPublic(true);
            }
        }
    }

    private function configureMonologHandler(ContainerBuilder $container, array $monologConfiguration): void
    {
        $errorHandler = $monologConfiguration['error_handler'];

        if (! $errorHandler['enabled']) {
            $container->removeDefinition(Handler::class);

            return;
        }

        if (! class_exists(Handler::class)) {
            throw new LogicException(
                sprintf('Missing class "%s", try updating "sentry/sentry" to a newer version.', Handler::class)
            );
        }

        if (! class_exists(MonologLogger::class)) {
            throw new LogicException(
                sprintf('You cannot use "%s" if Monolog is not available.', Handler::class)
            );
        }

        $container
            ->getDefinition(Handler::class)
            ->replaceArgument('$level', MonologLogger::toMonologLevel($errorHandler['level']))
            ->replaceArgument('$bubble', $errorHandler['bubble']);
    }
}
