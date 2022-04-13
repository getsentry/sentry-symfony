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
     * {@inheritdoc}
     */
    public function process(ContainerBuilder $container): void
    {
        if (
            !$container->getParameter('sentry.tracing.enabled')
            || !$container->getParameter('sentry.tracing.http_client.enabled')
        ) {
            return;
        }

        foreach ($container->findTaggedServiceIds('http_client.client') as $id => $tags) {
            $container->register('.sentry.traceable.' . $id, TraceableHttpClient::class)
                ->setArguments([
                    new Reference('.sentry.traceable.' . $id . '.inner'),
                    new Reference(HubInterface::class),
                ])
                ->addTag('kernel.reset', ['method' => 'reset'])
                ->setDecoratedService($id);
        }
    }
}
