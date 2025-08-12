<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\Command\SentryTestCommand;
use Sentry\SentryBundle\DependencyInjection\Compiler\CronMonitorPass;
use Sentry\SentryBundle\EventListener\CronMonitorListener;
use Sentry\State\HubInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

class CronMonitorPassTest extends TestCase
{
    public function testProcess(): void
    {
        $container = $this->createContainerBuilder(true);

        $container->setDefinition(
            SentryTestCommand::class,
            (new Definition(SentryTestCommand::class))
                ->setPublic(false)
                ->addTag('console.command')
                ->addTag('sentry.monitor_command', ['slug' => 'test-command'])
        );

        $container->compile();

        $insertedCronMonitorListenerArgument = $container->getDefinition(CronMonitorListener::class)->getArgument(1);

        $this->assertIsArray($insertedCronMonitorListenerArgument);
        $this->assertArrayHasKey(SentryTestCommand::class, $insertedCronMonitorListenerArgument);
        $this->assertEquals('test-command', $insertedCronMonitorListenerArgument[SentryTestCommand::class]);
    }

    public function testProcessDoesNothingIfConditionsForEnablingCronIsFalse(): void
    {
        $container = $this->createContainerBuilder(false);
        $container->compile();

        $this->assertFalse($container->getDefinition(CronMonitorListener::class)->getArgument(1));
    }

    private function createContainerBuilder(bool $isCronActive): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->addCompilerPass(new CronMonitorPass());
        $container->setParameter('sentry.cron.enabled', $isCronActive);

        $cronMonitorListenerMock = $this->createMock(CronMonitorListener::class);

        $container->setDefinition(CronMonitorListener::class, (new Definition(\get_class($cronMonitorListenerMock)))
            ->setPublic(true))
            ->setArgument(0, HubInterface::class)
            ->setArgument(1, false);

        return $container;
    }
}
