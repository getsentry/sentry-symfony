<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\Command\SentryTestCommand;
use Sentry\SentryBundle\DependencyInjection\Compiler\AddCronMonitorOptionsCompilerPass;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class AddCronMonitorOptionsCompilerPassTest extends TestCase
{
    public function testProcess(): void
    {
        $container = new ContainerBuilder();
        $container->register(SentryTestCommand::class)->setPublic(true)->addTag('console.command');
        $container->addCompilerPass(new AddCronMonitorOptionsCompilerPass());
        $container->compile();

        $commandDefinition = $container->getDefinition(SentryTestCommand::class);

        $this->assertSame([
            ['addOption', ['--cron-monitor-slug', '-cm', InputOption::VALUE_REQUIRED, 'if command should be monitored then pass cron monitor slug']],
            ['addOption', ['--cron-monitor-schedule', '-cms', InputOption::VALUE_REQUIRED, 'if command should be monitored then pass cron monitor schedule']],
            ['addOption', ['--cron-monitor-max-time', '-cmt', InputOption::VALUE_REQUIRED, 'if command should be monitored then pass cron monitor max execution time']],
            ['addOption', ['--cron-monitor-check-margin', '-cmcm', InputOption::VALUE_REQUIRED, 'if command should be monitored then pass cron monitor check margin']],
        ], $commandDefinition->getMethodCalls());
    }
}
