<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\DependencyInjection\Compiler;

use Sentry\SentryBundle\Tracing\HttpClient\TraceableHttpClient;
use Sentry\State\HubInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class HttpClientTracingPass implements CompilerPassInterface
{
    /**
     * List of service IDs that can be registered in the container by the
     * framework when decorating the mock client. The order is from the
     * outermost decorator to the innermost.
     */
    private const MOCK_HTTP_CLIENT_SERVICE_IDS = [
        'http_client.retryable.inner.mock_client',
        '.debug.http_client.inner.mock_client',
        'http_client.mock_client',
    ];

    /**
     * {@inheritdoc}
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->getParameter('sentry.tracing.enabled') || !$container->getParameter('sentry.tracing.http_client.enabled')) {
            return;
        }

        $decoratedService = $this->getDecoratedService($container);

        if (null === $decoratedService) {
            return;
        }

        $container->register(TraceableHttpClient::class, TraceableHttpClient::class)
            ->setArgument(0, new Reference(TraceableHttpClient::class . '.inner'))
            ->setArgument(1, new Reference(HubInterface::class))
            ->setDecoratedService($decoratedService[0], null, $decoratedService[1]);
    }

    /**
     * @return array{string, int}|null
     */
    private function getDecoratedService(ContainerBuilder $container): ?array
    {
        // Starting from Symfony 6.3, the raw HTTP client that serves as adapter
        // for the transport is registered as a separate service, so that the
        // scoped clients can inject it before any decoration is applied on them.
        // Since we need to access the full URL of the request, and such information
        // is available after the `ScopingHttpClient` class did its job, we have
        // to decorate such service. For more details, see https://github.com/symfony/symfony/pull/49513.
        if ($container->hasDefinition('http_client.transport')) {
            return ['http_client.transport', -15];
        }

        // On versions of Symfony prior to 6.3, when the mock client is in-use,
        // each HTTP client is decorated by referencing explicitly the innermost
        // service rather than by using the standard decoration feature. Hence,
        // we have to look for the specific names of those services, and decorate
        // them instead of the raw HTTP client.
        foreach (self::MOCK_HTTP_CLIENT_SERVICE_IDS as $httpClientServiceId) {
            if ($container->hasDefinition($httpClientServiceId)) {
                return [$httpClientServiceId, 15];
            }
        }

        if ($container->hasDefinition('http_client')) {
            return ['http_client', 15];
        }

        return null;
    }
}
