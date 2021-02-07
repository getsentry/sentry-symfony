<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\DependencyInjection\Compiler;

use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriverMiddleware;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class DbalTracingDriverPass implements CompilerPassInterface
{
    /**
     * {@inheritdoc}
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('doctrine.connections')) {
            return;
        }

        /** @var string[] $connections */
        $connections = $container->getParameter('doctrine.connections');

        /** @var string[] $connectionsToTrace */
        $connectionsToTrace = $container->getParameter('sentry.tracing.dbal.connections');

        foreach ($connectionsToTrace as $connectionName) {
            if (!\in_array(sprintf('doctrine.dbal.%s_connection', $connectionName), $connections, true)) {
                continue;
            }

            $configurationDefinition = $container->getDefinition(sprintf('doctrine.dbal.%s_connection.configuration', $connectionName));
            $setMiddlewaresMethodCallArguments = $this->getSetMiddlewaresMethodCallArguments($configurationDefinition);
            $setMiddlewaresMethodCallArguments[0] = array_merge($setMiddlewaresMethodCallArguments[0] ?? [], [new Reference(TracingDriverMiddleware::class)]);

            $configurationDefinition
                ->removeMethodCall('setMiddlewares')
                ->addMethodCall('setMiddlewares', $setMiddlewaresMethodCallArguments);
        }
    }

    /**
     * @return mixed[]
     */
    private function getSetMiddlewaresMethodCallArguments(Definition $definition): array
    {
        foreach ($definition->getMethodCalls() as $methodCall) {
            if ('setMiddlewares' === $methodCall[0]) {
                return $methodCall[1];
            }
        }

        return [];
    }
}
