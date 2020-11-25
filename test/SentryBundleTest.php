<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Test;

use PHPUnit\Framework\TestCase;
use Sentry\Integration\ErrorListenerIntegration;
use Sentry\Integration\ExceptionListenerIntegration;
use Sentry\Integration\IntegrationInterface;
use Sentry\Integration\RequestIntegration;
use Sentry\SentryBundle\Command\SentryTestCommand;
use Sentry\SentryBundle\DependencyInjection\SentryExtension;
use Sentry\SentryBundle\EventListener\ConsoleCommandListener;
use Sentry\SentryBundle\EventListener\ErrorListener;
use Sentry\SentryBundle\EventListener\RequestListener;
use Sentry\SentryBundle\EventListener\SubRequestListener;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\KernelEvents;

class SentryBundleTest extends TestCase
{
    public function testContainerHasConsoleListenerConfiguredCorrectly(): void
    {
        $container = $this->getContainer();

        $consoleListener = $container->getDefinition(ConsoleCommandListener::class);

        $expectedTag = [
            'kernel.event_listener' => [
                [
                    'event' => ConsoleEvents::COMMAND,
                    'method' => 'handleConsoleCommandEvent',
                    'priority' => '%sentry.listener_priorities.console%',
                ],
                [
                    'event' => ConsoleEvents::TERMINATE,
                    'method' => 'handleConsoleTerminateEvent',
                    'priority' => '%sentry.listener_priorities.console_terminate%',
                ],
                [
                    'event' => ConsoleEvents::ERROR,
                    'method' => 'handleConsoleErrorEvent',
                    'priority' => '%sentry.listener_priorities.console_error%',
                ],
            ],
        ];

        $this->assertSame($expectedTag, $consoleListener->getTags());
    }

    public function testContainerHasRequestListenerConfiguredCorrectly(): void
    {
        $container = $this->getContainer();

        $consoleListener = $container->getDefinition(RequestListener::class);

        $expectedTag = [
            'kernel.event_listener' => [
                [
                    'event' => KernelEvents::REQUEST,
                    'method' => 'handleKernelRequestEvent',
                    'priority' => '%sentry.listener_priorities.request%',
                ],
                [
                    'event' => KernelEvents::CONTROLLER,
                    'method' => 'handleKernelControllerEvent',
                    'priority' => '%sentry.listener_priorities.request%',
                ],
            ],
        ];

        $this->assertSame($expectedTag, $consoleListener->getTags());
    }

    public function testContainerHasSubRequestListenerConfiguredCorrectly(): void
    {
        $container = $this->getContainer();

        $consoleListener = $container->getDefinition(SubRequestListener::class);

        $expectedTag = [
            'kernel.event_listener' => [
                [
                    'event' => KernelEvents::REQUEST,
                    'method' => 'handleKernelRequestEvent',
                    'priority' => '%sentry.listener_priorities.sub_request%',
                ],
                [
                    'event' => KernelEvents::FINISH_REQUEST,
                    'method' => 'handleKernelFinishRequestEvent',
                    'priority' => '%sentry.listener_priorities.sub_request%',
                ],
            ],
        ];

        $this->assertSame($expectedTag, $consoleListener->getTags());
    }

    public function testContainerHasErrorListenerConfiguredCorrectly(): void
    {
        $container = $this->getContainer();

        $consoleListener = $container->getDefinition(ErrorListener::class);

        $expectedTag = [
            'kernel.event_listener' => [
                [
                    'event' => KernelEvents::EXCEPTION,
                    'method' => 'handleExceptionEvent',
                    'priority' => '%sentry.listener_priorities.request_error%',
                ],
            ],
        ];

        $this->assertSame($expectedTag, $consoleListener->getTags());
    }

    public function testContainerHasTestCommandRegisteredCorrectly(): void
    {
        $container = $this->getContainer();

        $consoleListener = $container->getDefinition(SentryTestCommand::class);

        $this->assertArrayHasKey('console.command', $consoleListener->getTags());
    }

    public function testIntegrationsListenersAreEnabled(): void
    {
        $container = $this->getContainer();

        $hub = $container->get(HubInterface::class);

        $this->assertInstanceOf(HubInterface::class, $hub);
        $this->assertInstanceOf(IntegrationInterface::class, $hub->getIntegration(RequestIntegration::class));
        $this->assertNotNull($hub->getIntegration(ErrorListenerIntegration::class));
        $this->assertNotNull($hub->getIntegration(ExceptionListenerIntegration::class));
    }

    private function getContainer(): ContainerBuilder
    {
        $containerBuilder = new ContainerBuilder();
        $containerBuilder->setParameter('kernel.cache_dir', 'var/cache');
        $containerBuilder->setParameter('kernel.project_dir', '/dir/project/root');

        $containerBuilder->setParameter('kernel.environment', 'test');
        $containerBuilder->set('event_dispatcher', $this->prophesize(EventDispatcherInterface::class)->reveal());

        $extension = new SentryExtension();
        $extension->load([], $containerBuilder);

        SentrySdk::setCurrentHub(new Hub());

        return $containerBuilder;
    }
}
