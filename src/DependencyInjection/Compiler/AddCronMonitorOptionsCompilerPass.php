<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\DependencyInjection\Compiler;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class AddCronMonitorOptionsCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container)
    {
        $optionsArguments = [
            ['--cron-monitor-slug', null, InputOption::VALUE_REQUIRED, 'if command should be monitored then pass cron monitor slug'],
            ['--cron-monitor-schedule', null, InputOption::VALUE_REQUIRED, 'if command should be monitored then pass cron monitor schedule'],
            ['--cron-monitor-max-time', null, InputOption::VALUE_REQUIRED, 'if command should be monitored then pass cron monitor max execution time'],
            ['--cron-monitor-check-margin', null, InputOption::VALUE_REQUIRED, 'if command should be monitored then pass cron monitor check margin'],
        ];

        $consoleCommands = $container->findTaggedServiceIds('console.command');
        foreach ($consoleCommands as $name => $consoleCommand) {
            $definition = $container->getDefinition($name);
            foreach ($optionsArguments as $optionArguments) {
                $definition->addMethodCall('addOption', $optionArguments);
            }
        }
    }
}
