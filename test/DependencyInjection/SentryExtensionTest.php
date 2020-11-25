<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Test\DependencyInjection;

use Jean85\PrettyVersions;
use Monolog\Logger as MonologLogger;
use PHPUnit\Framework\TestCase;
use Sentry\ClientInterface;
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
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

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

        $this->assertFalse($container->hasDefinition(ErrorListener::class));
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
            128,
        ];

        yield [
            'messenger_listener_overridden_priority',
            -128,
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
            'integrations' => [new Reference('App\\Sentry\\Integration\\FooIntegration')],
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
            'class_serializers' => [new Reference('App\\Sentry\\Serializer\\FooClassSerializer')],
            'dsn' => 'https://examplePublicKey@o0.ingest.sentry.io/0',
        ];

        $this->assertSame(Options::class, $optionsDefinition->getClass());
        $this->assertEquals($expectedOptions, $optionsDefinition->getArgument(0));

        $serializerDefinition = $container->getDefinition('sentry.client.serializer');

        $this->assertSame(Serializer::class, $serializerDefinition->getClass());
        $this->assertEquals(new Reference('sentry.client.options'), $serializerDefinition->getArgument(0));

        $representationSerializerDefinition = $container->getDefinition('sentry.client.representation_serializer');

        $this->assertSame(RepresentationSerializer::class, $representationSerializerDefinition->getClass());
        $this->assertEquals(new Reference('sentry.client.options'), $serializerDefinition->getArgument(0));

        $clientDefinition = $container->findDefinition(ClientInterface::class);

        $this->assertEquals(new Reference('sentry.client.options'), $clientDefinition->getArgument(0));
        $this->assertDefinitionMethodCallAt($clientDefinition, 0, 'setTransportFactory', [new Reference(TransportFactoryInterface::class)]);
        $this->assertDefinitionMethodCallAt($clientDefinition, 1, 'setSerializer', [new Reference('sentry.client.serializer')]);
        $this->assertDefinitionMethodCallAt($clientDefinition, 2, 'setRepresentationSerializer', [new Reference('sentry.client.representation_serializer')]);
        $this->assertDefinitionMethodCallAt($clientDefinition, 3, 'setSdkIdentifier', [SentryBundle::SDK_IDENTIFIER]);
        $this->assertDefinitionMethodCallAt($clientDefinition, 4, 'setSdkVersion', [PrettyVersions::getRootPackageVersion()->getPrettyVersion()]);
    }

    public function testEmptyDsnIsTreatedAsIfItWasUnset(): void
    {
        $container = $this->createContainerFromFixture('empty_dsn');
        $optionsDefinition = $container->getDefinition('sentry.client.options');

        $this->assertSame(Options::class, $optionsDefinition->getClass());
        $this->assertArrayNotHasKey('dsn', $optionsDefinition->getArgument(0));
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
     * @param mixed[] $arguments
     */
    private function assertDefinitionMethodCallAt(Definition $definition, int $methodCallIndex, string $method, array $arguments): void
    {
        $methodCalls = $definition->getMethodCalls();

        $this->assertArrayHasKey($methodCallIndex, $methodCalls);
        $this->assertSame($method, $methodCalls[$methodCallIndex][0]);
        $this->assertEquals($arguments, $methodCalls[$methodCallIndex][1]);
    }
}
