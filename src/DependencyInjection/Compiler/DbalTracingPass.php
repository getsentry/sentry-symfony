<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\DependencyInjection\Compiler;

use Doctrine\DBAL\Driver\ResultStatement;
use Doctrine\DBAL\Result;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\ConnectionConfigurator;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriverMiddleware;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class DbalTracingPass implements CompilerPassInterface
{
    /**
     * This is the format used by the DoctrineBundle bundle to register the
     * services for each connection.
     */
    private const CONNECTION_SERVICE_NAME_FORMAT = 'doctrine.dbal.%s_connection';

    /**
     * This is the format used by the DoctrineBundle bundle to register the
     * services for each connection's configuration.
     */
    private const CONNECTION_CONFIG_SERVICE_NAME_FORMAT = 'doctrine.dbal.%s_connection.configuration';

    /**
     * {@inheritdoc}
     */
    public function process(ContainerBuilder $container): void
    {
        if (
            !$container->hasParameter('doctrine.connections')
            || !$container->getParameter('sentry.tracing.enabled')
            || !$container->getParameter('sentry.tracing.dbal.enabled')
        ) {
            return;
        }

        if (!class_exists(Result::class)) {
            throw new \RuntimeException(sprintf('The Doctrine "%s" class is missing, DBAL connection cannot be instrumented; check that you have DBAL 2.13+ installed', Result::class));
        }

        /** @var string[] $connectionsToTrace */
        $connectionsToTrace = $container->getParameter('sentry.tracing.dbal.connections');

        /** @var array<string, string> $connections */
        $connections = $container->getParameter('doctrine.connections');

        if (empty($connectionsToTrace)) {
            $connectionsToTrace = array_keys($connections);
        }

        foreach ($connectionsToTrace as $connectionName) {
            if (!\in_array(sprintf(self::CONNECTION_SERVICE_NAME_FORMAT, $connectionName), $connections, true)) {
                throw new \InvalidArgumentException(sprintf('The Doctrine connection "%s" does not exists and cannot be instrumented.', $connectionName));
            }

            if (!interface_exists(ResultStatement::class)) {
                $this->configureConnectionForDoctrineDBALVersion3($container, $connectionName);
            } else {
                $this->configureConnectionForDoctrineDBALVersion2($container, $connectionName);
            }
        }
    }

    private function configureConnectionForDoctrineDBALVersion3(ContainerBuilder $container, string $connectionName): void
    {
        $configurationDefinition = $container->getDefinition(sprintf(self::CONNECTION_CONFIG_SERVICE_NAME_FORMAT, $connectionName));
        $setMiddlewaresMethodCallArguments = $this->getSetMiddlewaresMethodCallArguments($configurationDefinition);
        $setMiddlewaresMethodCallArguments[0] = array_merge($setMiddlewaresMethodCallArguments[0] ?? [], [new Reference(TracingDriverMiddleware::class)]);

        $configurationDefinition
            ->removeMethodCall('setMiddlewares')
            ->addMethodCall('setMiddlewares', $setMiddlewaresMethodCallArguments);
    }

    private function configureConnectionForDoctrineDBALVersion2(ContainerBuilder $container, string $connectionName): void
    {
        $connectionDefinition = $container->getDefinition(sprintf(self::CONNECTION_SERVICE_NAME_FORMAT, $connectionName));
        $connectionDefinition->setConfigurator([new Reference(ConnectionConfigurator::class), 'configure']);
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
