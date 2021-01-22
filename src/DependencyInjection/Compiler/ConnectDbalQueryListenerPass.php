<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\DependencyInjection\Compiler;

use Sentry\SentryBundle\EventListener\Tracing\DbalListener;
use Sentry\State\HubInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

class ConnectDbalQueryListenerPass implements CompilerPassInterface
{
    /**
     * @return void
     */
    public function process(ContainerBuilder $container)
    {
        $config = $container->getExtensionConfig('sentry');

        $registerDbalListener = isset($config[0]['register_dbal_listener'])
            ? $config[0]['register_dbal_listener']
            : false;

        if ($registerDbalListener && $container->hasDefinition('doctrine.dbal.logger')) {
            $container->setDefinition(
                'doctrine.dbal.logger',
                new Definition(DbalListener::class, [$container->getDefinition(HubInterface::class)])
            );
        }
    }
}
