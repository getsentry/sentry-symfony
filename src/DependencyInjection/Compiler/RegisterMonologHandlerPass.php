<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\DependencyInjection\Compiler;

use Sentry\Monolog\Handler;
use Sentry\SentryBundle\Monolog\SymfonyHandler;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class RegisterMonologHandlerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(Handler::class)) {
            return;
        }

        $this->registerMainHandler($container);
    }

    private function registerMainHandler(ContainerBuilder $container): void
    {
        foreach ($container->getServiceIds() as $serviceName) {
            if (!str_starts_with($serviceName, 'monolog.logger.')) {
                continue;
            }

            $logger = $container->getDefinition($serviceName);
            $logger->addMethodCall('pushHandler', [new Reference(SymfonyHandler::class)]);
        }
    }
}
