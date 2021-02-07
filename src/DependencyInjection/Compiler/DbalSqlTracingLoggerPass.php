<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\DependencyInjection\Compiler;

use Doctrine\DBAL\Logging\LoggerChain;
use Sentry\SentryBundle\Tracing\DbalSqlTracingLogger;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class DbalSqlTracingLoggerPass implements CompilerPassInterface
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
            if (!\in_array($connectionName, $connections, true)) {
                continue;
            }

            $configurationDefinition = $container->getDefinition(sprintf('doctrine.dbal.%s_connection.configuration', $connectionName));
            $setSQLLoggerMethodCall = $this->getSetSQLLoggerMethodCall($configurationDefinition);

            if (null === $setSQLLoggerMethodCall) {
                $loggerDefinition = new Reference(DbalSqlTracingLogger::class);
            } else {
                $loggerDefinition = $this->addTracingLoggerToConnectionConfiguration($container, $setSQLLoggerMethodCall[1][0]);
            }

            $configurationDefinition
                ->removeMethodCall('setSQLLogger')
                ->addMethodCall('setSQLLogger', [$loggerDefinition]);
        }
    }

    /**
     * @return array{0: string, 1: mixed[]}|null
     */
    private function getSetSQLLoggerMethodCall(Definition $definition): ?array
    {
        foreach ($definition->getMethodCalls() as $methodCall) {
            if ('setSQLLogger' === $methodCall[0]) {
                return $methodCall;
            }
        }

        return null;
    }

    /**
     * @param Reference|Definition $loggerDefinition The service definition
     *
     * @return Reference|Definition
     */
    private function addTracingLoggerToConnectionConfiguration(ContainerBuilder $container, $loggerDefinition)
    {
        if ($loggerDefinition instanceof Definition) {
            if (LoggerChain::class === $loggerDefinition->getClass()) {
                $loggerDefinition->setArgument(0, array_merge($loggerDefinition->getArgument(0), [new Reference(DbalSqlTracingLogger::class)]));
            } else {
                $loggerDefinition = new Definition(LoggerChain::class, [[
                    $loggerDefinition,
                    new Reference(DbalSqlTracingLogger::class),
                ]]);
            }
        } else {
            $realLoggerDefinition = $container->findDefinition((string) $loggerDefinition);

            if (LoggerChain::class === $realLoggerDefinition->getClass()) {
                $realLoggerDefinition->setArgument(0, array_merge($realLoggerDefinition->getArgument(0), [new Reference(DbalSqlTracingLogger::class)]));
            } else {
                $loggerDefinition = new Definition(LoggerChain::class, [[
                    $loggerDefinition,
                    new Reference(DbalSqlTracingLogger::class),
                ]]);
            }
        }

        return $loggerDefinition;
    }
}
