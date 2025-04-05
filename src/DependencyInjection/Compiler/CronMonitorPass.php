<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\DependencyInjection\Compiler;

use Sentry\SentryBundle\EventListener\CronMonitorListener;
use Symfony\Component\DependencyInjection\Compiler\PriorityTaggedServiceTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;

class CronMonitorPass implements CompilerPassInterface
{
    use PriorityTaggedServiceTrait;

    public function process(ContainerBuilder $container): void
    {
        if (!$container->getParameter('sentry.cron.enabled')) {
            return;
        }

        $commands = $this->findAndSortTaggedServices('sentry.monitor_command', $container);

        $commandSlugMapping = [];
        foreach ($commands as $reference) {
            $id = $reference->__toString();
            foreach ($container->getDefinition($id)->getTag('sentry.monitor_command') as $attributes) {
                $commandSlugMapping[$id] = $attributes['slug'];
            }
        }

        $container->getDefinition(CronMonitorListener::class)->setArgument(1, $commandSlugMapping);
    }
}
