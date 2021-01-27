<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\DependencyInjection\Compiler;

use Sentry\SentryBundle\Tracing\DbalSqlTracingLogger;
use Sentry\State\HubInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class ConnectDbalQueryListenerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $config = $container->getExtensionConfig('sentry');

        $dbalTracingEnabled = isset($config[0]['tracing']['dbal_tracing'])
            ? $config[0]['tracing']['dbal_tracing']
            : false;

        if ($dbalTracingEnabled && $container->hasDefinition('doctrine.dbal.logger')) {
            $container->setDefinition(
                'doctrine.dbal.logger',
                new Definition(DbalSqlTracingLogger::class, [$container->getDefinition(HubInterface::class)])
            );
        }
    }
}
