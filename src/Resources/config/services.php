<?php

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return static function (ContainerConfigurator $container) {
    $services = $container->services();

    $services->defaults()
        ->private();

    $services->alias('Sentry\ClientInterface', 'sentry.client');

    $services->set('Sentry\State\HubInterface')
        ->factory(['Sentry\State\HubAdapter', 'getInstance'])
        ->call('bindClient', [service('Sentry\ClientInterface')]);

    $services->alias('Sentry\SentryBundle\EventListener\ConsoleCommandListener', 'Sentry\SentryBundle\EventListener\ConsoleListener');

    $services->set('Sentry\SentryBundle\EventListener\ConsoleListener', 'Sentry\SentryBundle\EventListener\ConsoleListener')
        ->args([service('Sentry\State\HubInterface')])
        ->tag('kernel.event_listener', ['event' => 'console.command', 'method' => 'handleConsoleCommandEvent', 'priority' => 128])
        ->tag('kernel.event_listener', ['event' => 'console.terminate', 'method' => 'handleConsoleTerminateEvent', 'priority' => -64])
        ->tag('kernel.event_listener', ['event' => 'console.error', 'method' => 'handleConsoleErrorEvent', 'priority' => -64]);

    $services->set('Sentry\SentryBundle\EventListener\ErrorListener', 'Sentry\SentryBundle\EventListener\ErrorListener')
        ->args([service('Sentry\State\HubInterface')])
        ->tag('kernel.event_listener', ['event' => 'kernel.exception', 'method' => 'handleExceptionEvent', 'priority' => 128]);

    $services->set('Sentry\SentryBundle\EventListener\RequestListener', 'Sentry\SentryBundle\EventListener\RequestListener')
        ->args([service('Sentry\State\HubInterface')])
        ->tag('kernel.event_listener', ['event' => 'kernel.request', 'method' => 'handleKernelRequestEvent', 'priority' => 5])
        ->tag('kernel.event_listener', ['event' => 'kernel.controller', 'method' => 'handleKernelControllerEvent', 'priority' => 10]);

    $services->set('Sentry\SentryBundle\EventListener\SubRequestListener', 'Sentry\SentryBundle\EventListener\SubRequestListener')
        ->args([service('Sentry\State\HubInterface')])
        ->tag('kernel.event_listener', ['event' => 'kernel.request', 'method' => 'handleKernelRequestEvent', 'priority' => 3])
        ->tag('kernel.event_listener', ['event' => 'kernel.finish_request', 'method' => 'handleKernelFinishRequestEvent', 'priority' => 5]);

    $services->set('Sentry\SentryBundle\EventListener\TracingRequestListener', 'Sentry\SentryBundle\EventListener\TracingRequestListener')
        ->args([
            service('Sentry\State\HubInterface'),
            service('Sentry\Integration\RequestFetcherInterface'),
        ])
        ->tag('kernel.event_listener', ['event' => 'kernel.request', 'method' => 'handleKernelRequestEvent', 'priority' => 4])
        ->tag('kernel.event_listener', ['event' => 'kernel.response', 'method' => 'handleKernelResponseEvent', 'priority' => 15])
        ->tag('kernel.event_listener', ['event' => 'kernel.terminate', 'method' => 'handleKernelTerminateEvent', 'priority' => 5]);

    $services->set('Sentry\SentryBundle\EventListener\TracingSubRequestListener', 'Sentry\SentryBundle\EventListener\TracingSubRequestListener')
        ->args([service('Sentry\State\HubInterface')])
        ->tag('kernel.event_listener', ['event' => 'kernel.request', 'method' => 'handleKernelRequestEvent', 'priority' => 2])
        ->tag('kernel.event_listener', ['event' => 'kernel.finish_request', 'method' => 'handleKernelFinishRequestEvent', 'priority' => 10])
        ->tag('kernel.event_listener', ['event' => 'kernel.response', 'method' => 'handleKernelResponseEvent', 'priority' => 15]);

    $services->set('Sentry\SentryBundle\EventListener\TracingConsoleListener', 'Sentry\SentryBundle\EventListener\TracingConsoleListener')
        ->args([
            service('Sentry\State\HubInterface'),
            '',
        ])
        ->tag('kernel.event_listener', ['event' => 'console.command', 'method' => 'handleConsoleCommandEvent', 'priority' => 118])
        ->tag('kernel.event_listener', ['event' => 'console.terminate', 'method' => 'handleConsoleTerminateEvent', 'priority' => -54]);

    $services->set('Sentry\SentryBundle\EventListener\MessengerListener', 'Sentry\SentryBundle\EventListener\MessengerListener')
        ->args([service('Sentry\State\HubInterface')])
        ->tag('kernel.event_listener', ['event' => 'Symfony\Component\Messenger\Event\WorkerMessageFailedEvent', 'method' => 'handleWorkerMessageFailedEvent', 'priority' => 50])
        ->tag('kernel.event_listener', ['event' => 'Symfony\Component\Messenger\Event\WorkerMessageHandledEvent', 'method' => 'handleWorkerMessageHandledEvent', 'priority' => 50])
        ->tag('kernel.event_listener', ['event' => 'Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent', 'method' => 'handleWorkerMessageReceivedEvent', 'priority' => 50]);

    $services->set('Sentry\SentryBundle\EventListener\LoginListener', 'Sentry\SentryBundle\EventListener\LoginListener')
        ->args([
            service('Sentry\State\HubInterface'),
            service('security.token_storage')->ignoreOnInvalid(),
        ])
        ->tag('kernel.event_listener', ['event' => 'kernel.request', 'method' => 'handleKernelRequestEvent']);

    $services->set('Sentry\SentryBundle\EventListener\LogRequestListener', 'Sentry\SentryBundle\EventListener\LogRequestListener')
        ->tag('kernel.event_listener', ['event' => 'kernel.terminate', 'method' => 'handleKernelTerminateEvent', 'priority' => 10]);

    $services->set('Sentry\SentryBundle\Command\SentryTestCommand', 'Sentry\SentryBundle\Command\SentryTestCommand')
        ->args([service('Sentry\State\HubInterface')])
        ->tag('console.command', ['command' => 'sentry:test']);

    $services->alias('Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriverConnectionFactoryInterface', 'Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriverConnectionFactory');

    $services->set('Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriverConnectionFactory', 'Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriverConnectionFactory')
        ->args([service('Sentry\State\HubInterface')]);

    $services->set('Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriverMiddleware', 'Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriverMiddleware')
        ->args([service('Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriverConnectionFactoryInterface')]);

    $services->set('Sentry\SentryBundle\Tracing\Doctrine\DBAL\ConnectionConfigurator', 'Sentry\SentryBundle\Tracing\Doctrine\DBAL\ConnectionConfigurator')
        ->args([service('Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriverMiddleware')]);

    $services->set('Sentry\SentryBundle\Tracing\Twig\TwigTracingExtension', 'Sentry\SentryBundle\Tracing\Twig\TwigTracingExtension')
        ->args([service('Sentry\State\HubInterface')])
        ->tag('twig.extension');

    $services->set('sentry.tracing.traceable_cache_adapter', 'Sentry\SentryBundle\Tracing\Cache\TraceableCacheAdapter')
        ->abstract()
        ->args([
            service('Sentry\State\HubInterface'),
            '',
        ]);

    $services->set('sentry.tracing.traceable_tag_aware_cache_adapter', 'Sentry\SentryBundle\Tracing\Cache\TraceableTagAwareCacheAdapter')
        ->abstract()
        ->args([
            service('Sentry\State\HubInterface'),
            '',
        ]);

    $services->set('Sentry\SentryBundle\Integration\IntegrationConfigurator', 'Sentry\SentryBundle\Integration\IntegrationConfigurator')
        ->args([
            [],
            '',
        ]);

    $services->set('Sentry\Integration\RequestFetcherInterface', 'Sentry\SentryBundle\Integration\RequestFetcher')
        ->args([
            service('Symfony\Component\HttpFoundation\RequestStack'),
            service('Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface')->nullOnInvalid(),
        ]);

    $services->set('Sentry\SentryBundle\Twig\SentryExtension', 'Sentry\SentryBundle\Twig\SentryExtension')
        ->tag('twig.extension');
};
