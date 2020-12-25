<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Test\DependencyInjection;

use Jean85\PrettyVersions;
use Monolog\Logger as MonologLogger;
use PHPUnit\Framework\TestCase;
use Sentry\ClientInterface;
use Sentry\Integration\IgnoreErrorsIntegration;
use Sentry\Monolog\Handler;
use Sentry\Options;
use Sentry\SentryBundle\DependencyInjection\SentryExtension;
use Sentry\SentryBundle\EventListener\ConsoleCommandListener;
use Sentry\SentryBundle\EventListener\ErrorListener;
use Sentry\SentryBundle\EventListener\MessengerListener;
use Sentry\SentryBundle\EventListener\RequestListener;
use Sentry\SentryBundle\EventListener\SubRequestListener;
use Sentry\SentryBundle\SentryBundle;
use Sentry\Serializer\RepresentationSerializer;
use Sentry\Serializer\Serializer;
use Sentry\Transport\TransportFactoryInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\ParameterBag\EnvPlaceholderParameterBag;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\ErrorHandler\Error\FatalError;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\MessageBusInterface;

abstract class SentryExtensionTest extends TestCase
{
    abstract protected function loadFixture(ContainerBuilder $container, string $fixtureFile): void;

    /**
     * @dataProvider errorListenerDataProvider
     */
    public function testErrorListener(string $fixture, int $tagPriority): void
    {
        $container = $this->createContainerFromFixture($fixture);
        $definition = $container->getDefinition(ErrorListener::class);

        $this->assertSame($tagPriority, $container->getParameter('sentry.listener_priorities.request_error'));
        $this->assertSame(ErrorListener::class, $definition->getClass());
        $this->assertSame([
            'event' => KernelEvents::EXCEPTION,
            'method' => 'handleExceptionEvent',
            'priority' => '%sentry.listener_priorities.request_error%',
        ], $definition->getTag('kernel.event_listener')[0]);
    }

    /**
     * @return \Generator<mixed>
     */
    public function errorListenerDataProvider(): \Generator
    {
        yield [
            'full',
            128,
        ];

        yield [
            'error_listener_overridden_priority',
            -128,
        ];
    }

    public function testErrorListenerIsRemovedWhenDisabled(): void
    {
        $container = $this->createContainerFromFixture('error_listener_disabled');
        $optionsDefinition = $container->getDefinition('sentry.client.options');

        $this->assertFalse($container->hasDefinition(ErrorListener::class));
        $this->assertSame([], $optionsDefinition->getArgument(0)['integrations']);
    }

    /**
     * @dataProvider consoleCommandListenerDataProvider
     */
    public function testConsoleCommandListener(string $fixture, int $consoleCommandTagPriority, int $consoleTerminateTagPriority, int $consoleErrorTagPriority): void
    {
        $container = $this->createContainerFromFixture($fixture);
        $definition = $container->getDefinition(ConsoleCommandListener::class);

        $this->assertSame($consoleCommandTagPriority, $container->getParameter('sentry.listener_priorities.console'));
        $this->assertSame($consoleTerminateTagPriority, $container->getParameter('sentry.listener_priorities.console_terminate'));
        $this->assertSame($consoleErrorTagPriority, $container->getParameter('sentry.listener_priorities.console_error'));
        $this->assertSame(ConsoleCommandListener::class, $definition->getClass());
        $this->assertSame([
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
        ], $definition->getTags());
    }

    /**
     * @return \Generator<mixed>
     */
    public function consoleCommandListenerDataProvider(): \Generator
    {
        yield [
            'full',
            128,
            -64,
            -64,
        ];

        yield [
            'console_command_listener_overridden_priority',
            -128,
            64,
            64,
        ];
    }

    /**
     * @dataProvider messengerListenerDataProvider
     */
    public function testMessengerListener(string $fixture, int $tagPriority): void
    {
        if (!interface_exists(MessageBusInterface::class)) {
            $this->markTestSkipped('This test requires the "symfony/messenger" Composer package to be installed.');
        }

        $container = $this->createContainerFromFixture($fixture);
        $definition = $container->getDefinition(MessengerListener::class);

        $this->assertSame($tagPriority, $container->getParameter('sentry.listener_priorities.worker_error'));
        $this->assertSame(MessengerListener::class, $definition->getClass());
        $this->assertSame([
            'kernel.event_listener' => [
                [
                    'event' => WorkerMessageFailedEvent::class,
                    'method' => 'handleWorkerMessageFailedEvent',
                    'priority' => '%sentry.listener_priorities.worker_error%',
                ],
                [
                    'event' => WorkerMessageHandledEvent::class,
                    'method' => 'handleWorkerMessageHandledEvent',
                    'priority' => '%sentry.listener_priorities.worker_error%',
                ],
            ],
        ], $definition->getTags());
    }

    /**
     * @return \Generator<mixed>
     */
    public function messengerListenerDataProvider(): \Generator
    {
        yield [
            'full',
            50,
        ];

        yield [
            'messenger_listener_overridden_priority',
            -50,
        ];
    }

    public function testMessengerListenerIsRemovedWhenDisabled(): void
    {
        $container = $this->createContainerFromFixture('messenger_listener_disabled');

        $this->assertFalse($container->hasDefinition(MessengerListener::class));
    }

    /**
     * @dataProvider requestListenerDataProvider
     */
    public function testRequestListener(string $fixture, int $tagPriority): void
    {
        $container = $this->createContainerFromFixture($fixture);
        $definition = $container->getDefinition(RequestListener::class);

        $this->assertSame($tagPriority, $container->getParameter('sentry.listener_priorities.request'));
        $this->assertSame(RequestListener::class, $definition->getClass());
        $this->assertSame([
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
        ], $definition->getTags());
    }

    /**
     * @return \Generator<mixed>
     */
    public function requestListenerDataProvider(): \Generator
    {
        yield [
            'full',
            1,
        ];

        yield [
            'request_listener_overridden_priority',
            -1,
        ];
    }

    /**
     * @dataProvider subRequestListenerDataProvider
     */
    public function testSubRequestListener(string $fixture, int $tagPriority): void
    {
        $container = $this->createContainerFromFixture($fixture);
        $definition = $container->getDefinition(SubRequestListener::class);

        $this->assertSame($tagPriority, $container->getParameter('sentry.listener_priorities.sub_request'));
        $this->assertSame(SubRequestListener::class, $definition->getClass());
        $this->assertSame([
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
        ], $definition->getTags());
    }

    /**
     * @return \Generator<mixed>
     */
    public function subRequestListenerDataProvider(): \Generator
    {
        yield [
            'full',
            1,
        ];

        yield [
            'sub_request_listener_overridden_priority',
            -1,
        ];
    }

    public function testMonologHandler(): void
    {
        $container = $this->createContainerFromFixture('monolog_handler');
        $definition = $container->getDefinition(Handler::class);

        $this->assertSame(MonologLogger::ERROR, $definition->getArgument(0));
        $this->assertFalse($definition->getArgument(1));
    }

    public function testMonologHandlerIsRemovedWhenDisabled(): void
    {
        $container = $this->createContainerFromFixture('monolog_handler_disabled');

        $this->assertFalse($container->hasDefinition(Handler::class));
    }

    public function testClentIsCreatedFromOptions(): void
    {
        $container = $this->createContainerFromFixture('full');
        $optionsDefinition = $container->getDefinition('sentry.client.options');
        $expectedOptions = [
            'integrations' => [
                new Definition(IgnoreErrorsIntegration::class, [['ignore_exceptions' => [FatalError::class]]]),
                new Reference('App\\Sentry\\Integration\\FooIntegration'),
            ],
            'default_integrations' => false,
            'send_attempts' => 1,
            'prefixes' => [$container->getParameter('kernel.project_dir')],
            'sample_rate' => 1,
            'traces_sample_rate' => 1,
            'traces_sampler' => new Reference('App\\Sentry\\Tracing\\TracesSampler'),
            'attach_stacktrace' => true,
            'context_lines' => 0,
            'enable_compression' => true,
            'environment' => 'development',
            'logger' => 'php',
            'release' => '4.0.x-dev',
            'server_name' => 'localhost',
            'before_send' => new Reference('App\\Sentry\\BeforeSendCallback'),
            'tags' => ['context' => 'development'],
            'error_types' => E_ALL,
            'max_breadcrumbs' => 1,
            'before_breadcrumb' => new Reference('App\\Sentry\\BeforeBreadcrumbCallback'),
            'in_app_exclude' => [$container->getParameter('kernel.cache_dir')],
            'in_app_include' => [$container->getParameter('kernel.project_dir')],
            'send_default_pii' => true,
            'max_value_length' => 255,
            'http_proxy' => 'proxy.example.com:8080',
            'capture_silenced_errors' => true,
            'max_request_body_size' => 'none',
            'class_serializers' => [
                'App\\FooClass' => new Reference('App\\Sentry\\Serializer\\FooClassSerializer'),
            ],
            'dsn' => 'https://examplePublicKey@o0.ingest.sentry.io/0',
        ];

        $this->assertSame(Options::class, $optionsDefinition->getClass());
        $this->assertEquals($expectedOptions, $optionsDefinition->getArgument(0));

        $clientDefinition = $container->findDefinition(ClientInterface::class);
        $factory = $clientDefinition->getFactory();

        $this->assertInstanceOf(Definition::class, $factory[0]);
        $this->assertSame('getClient', $factory[1]);

        $methodCalls = $factory[0]->getMethodCalls();

        $this->assertDefinitionMethodCallAt($methodCalls[0], 'setSdkIdentifier', [SentryBundle::SDK_IDENTIFIER]);
        $this->assertDefinitionMethodCallAt($methodCalls[1], 'setSdkVersion', [PrettyVersions::getRootPackageVersion()->getPrettyVersion()]);
        $this->assertDefinitionMethodCallAt($methodCalls[2], 'setTransportFactory', [new Reference(TransportFactoryInterface::class)]);

        $this->assertSame('setSerializer', $methodCalls[3][0]);
        $this->assertInstanceOf(Definition::class, $methodCalls[3][1][0]);
        $this->assertSame(Serializer::class, $methodCalls[3][1][0]->getClass());
        $this->assertEquals($methodCalls[3][1][0]->getArgument(0), new Reference('sentry.client.options'));

        $this->assertSame('setRepresentationSerializer', $methodCalls[4][0]);
        $this->assertInstanceOf(Definition::class, $methodCalls[4][1][0]);
        $this->assertSame(RepresentationSerializer::class, $methodCalls[4][1][0]->getClass());
        $this->assertEquals($methodCalls[4][1][0]->getArgument(0), new Reference('sentry.client.options'));
    }

    public function testEmptyDsnIsTreatedAsIfItWasUnset(): void
    {
        $container = $this->createContainerFromFixture('empty_dsn');
        $optionsDefinition = $container->getDefinition('sentry.client.options');

        $this->assertArrayNotHasKey('dsn', $optionsDefinition->getArgument(0));
    }

    public function testErrorTypesOptionIsParsedFromStringToIntegerValue(): void
    {
        $container = $this->createContainerFromFixture('error_types');
        $optionsDefinition = $container->getDefinition('sentry.client.options');

        $this->assertSame(E_ALL & ~(E_NOTICE | E_STRICT | E_DEPRECATED), $optionsDefinition->getArgument(0)['error_types']);
    }

    public function testIgnoreErrorsIntegrationIsNotAddedTwiceIfAlreadyConfigured(): void
    {
        $container = $this->createContainerFromFixture('ignore_errors_integration_overridden');
        $optionsDefinition = $container->getDefinition('sentry.client.options');
        $expectedIntegrations = [
            new Reference('App\\Sentry\\Integration\\FooIntegration'),
            new Reference(IgnoreErrorsIntegration::class),
        ];

        $this->assertEquals($expectedIntegrations, $optionsDefinition->getArgument(0)['integrations']);
    }

    private function createContainerFromFixture(string $fixtureFile): ContainerBuilder
    {
        $container = new ContainerBuilder(new EnvPlaceholderParameterBag([
            'kernel.cache_dir' => __DIR__,
            'kernel.build_dir' => __DIR__,
            'kernel.project_dir' => __DIR__,
        ]));

        $container->registerExtension(new SentryExtension());
        $container->getCompilerPassConfig()->setOptimizationPasses([]);
        $container->getCompilerPassConfig()->setRemovingPasses([]);
        $container->getCompilerPassConfig()->setAfterRemovingPasses([]);

        $this->loadFixture($container, $fixtureFile);

        $container->compile();

        return $container;
    }

    /**
     * @param array<int, mixed> $methodCall
     * @param mixed[] $arguments
     */
    private function assertDefinitionMethodCallAt(array $methodCall, string $method, array $arguments): void
    {
        $this->assertSame($method, $methodCall[0]);
        $this->assertEquals($arguments, $methodCall[1]);
    }
}
