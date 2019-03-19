<?php

namespace Sentry\SentryBundle\Test;

use PHPUnit\Framework\TestCase;
use Sentry\Integration\ErrorListenerIntegration;
use Sentry\Integration\ExceptionListenerIntegration;
use Sentry\Integration\IntegrationInterface;
use Sentry\Integration\RequestIntegration;
use Sentry\SentryBundle\DependencyInjection\SentryExtension;
use Sentry\SentryBundle\EventListener\ConsoleListener;
use Sentry\SentryBundle\EventListener\ErrorListener;
use Sentry\SentryBundle\EventListener\RequestListener;
use Sentry\SentryBundle\EventListener\SubRequestListener;
use Sentry\State\HubInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpKernel\KernelEvents;

class SentryBundleTest extends TestCase
{
    public function testContainerHasConsoleListenerConfiguredCorrectly(): void
    {
        $container = $this->getContainer();

        $consoleListener = $container->getDefinition(ConsoleListener::class);

        $expectedTag = [
            'kernel.event_listener' => [
                [
                    'event' => ConsoleEvents::COMMAND,
                    'method' => 'onConsoleCommand',
                    'priority' => '%sentry.listener_priorities.console%',
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
                    'method' => 'onKernelRequest',
                    'priority' => '%sentry.listener_priorities.request%',
                ],
                [
                    'event' => KernelEvents::CONTROLLER,
                    'method' => 'onKernelController',
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
                    'method' => 'onKernelRequest',
                    'priority' => '%sentry.listener_priorities.sub_request%',
                ],
                [
                    'event' => KernelEvents::FINISH_REQUEST,
                    'method' => 'onKernelFinishRequest',
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
                    'method' => 'onKernelException',
                    'priority' => '%sentry.listener_priorities.request_error%',
                ],
            ],
        ];

        if (class_exists('Symfony\Component\Console\Event\ConsoleErrorEvent')) {
            $expectedTag['kernel.event_listener'][] = [
                'event' => ConsoleEvents::ERROR,
                'method' => 'onConsoleError',
                'priority' => '%sentry.listener_priorities.console_error%',
            ];
        } else {
            $expectedTag['kernel.event_listener'][] = [
                'event' => ConsoleEvents::EXCEPTION,
                'method' => 'onConsoleException',
                'priority' => '%sentry.listener_priorities.console_error%',
            ];
        }

        $this->assertSame($expectedTag, $consoleListener->getTags());
    }

    public function testIntegrationsListenersAreDisabledByDefault(): void
    {
        $container = $this->getContainer();

        $hub = $container->get(HubInterface::class);

        $this->assertInstanceOf(HubInterface::class, $hub);
        $this->assertInstanceOf(IntegrationInterface::class, $hub->getIntegration(RequestIntegration::class));
        $this->assertNull($hub->getIntegration(ErrorListenerIntegration::class));
        $this->assertNull($hub->getIntegration(ExceptionListenerIntegration::class));
    }

    private function getContainer(array $configuration = []): ContainerBuilder
    {
        $containerBuilder = new ContainerBuilder();
        $containerBuilder->setParameter('kernel.root_dir', 'kernel/root');
        $containerBuilder->setParameter('kernel.cache_dir', 'var/cache');
        if (method_exists(Kernel::class, 'getProjectDir')) {
            $containerBuilder->setParameter('kernel.project_dir', '/dir/project/root');
        }

        $containerBuilder->setParameter('kernel.environment', 'test');
        $containerBuilder->set('event_dispatcher', $this->prophesize(EventDispatcherInterface::class)->reveal());

        $extension = new SentryExtension();
        $extension->load(['sentry' => $configuration], $containerBuilder);

        return $containerBuilder;
    }
}
