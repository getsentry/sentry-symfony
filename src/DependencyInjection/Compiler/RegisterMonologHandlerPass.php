<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\DependencyInjection\Compiler;

use Sentry\Monolog\Handler;
use Sentry\Options;
use Sentry\SentryBundle\Integration\IgnoreFatalErrorExceptionsIntegration;
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

    private function registerIntegrations(ContainerBuilder $container): void
    {
        $options = $container->getDefinition(Options::class);
        $input = $options->getArgument(0);
        $input['integrations'][] = IgnoreFatalErrorExceptionsIntegration::class;
        $options->setArgument(0, $input);
    }
}
