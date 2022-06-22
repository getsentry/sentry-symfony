<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\DependencyInjection;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Jean85\PrettyVersions;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sentry\ClientInterface;
use Sentry\Integration\IgnoreErrorsIntegration;
use Sentry\Options;
use Sentry\SentryBundle\DependencyInjection\SentryExtension;
use Sentry\SentryBundle\EventListener\ConsoleListener;
use Sentry\SentryBundle\EventListener\ErrorListener;
use Sentry\SentryBundle\EventListener\MessengerListener;
use Sentry\SentryBundle\EventListener\RequestListener;
use Sentry\SentryBundle\EventListener\SubRequestListener;
use Sentry\SentryBundle\EventListener\TracingConsoleListener;
use Sentry\SentryBundle\EventListener\TracingRequestListener;
use Sentry\SentryBundle\EventListener\TracingSubRequestListener;
use Sentry\SentryBundle\SentryBundle;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\ConnectionConfigurator;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriverMiddleware;
use Sentry\SentryBundle\Tracing\Twig\TwigTracingExtension;
use Sentry\Serializer\RepresentationSerializer;
use Sentry\Serializer\Serializer;
use Sentry\Transport\TransportFactoryInterface;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Debug\Exception\FatalErrorException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\ParameterBag\EnvPlaceholderParameterBag;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\ErrorHandler\Error\FatalError;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\MessageBusInterface;

abstract class SentryExtensionTest extends TestCase
{
    abstract protected function loadFixture(ContainerBuilder $container, string $fixtureFile): void;

    public function testErrorListener(): void
    {
        $container = $this->createContainerFromFixture('full');
        $definition = $container->getDefinition(ErrorListener::class);

        $this->assertSame(ErrorListener::class, $definition->getClass());
        $this->assertSame([
            'event' => KernelEvents::EXCEPTION,
            'method' => 'handleExceptionEvent',
            'priority' => 128,
        ], $definition->getTag('kernel.event_listener')[0]);
    }

    public function testErrorListenerIsRemovedWhenDisabled(): void
    {
        $container = $this->createContainerFromFixture('error_listener_disabled');

        $this->assertFalse($container->hasDefinition(ErrorListener::class));
    }

    public function testConsoleCommandListener(): void
    {
        $container = $this->createContainerFromFixture('full');
        $definition = $container->findDefinition(ConsoleListener::class);

        $this->assertSame(ConsoleListener::class, $definition->getClass());
        $this->assertSame([
            'kernel.event_listener' => [
                [
                    'event' => ConsoleEvents::COMMAND,
                    'method' => 'handleConsoleCommandEvent',
                    'priority' => 128,
                ],
                [
                    'event' => ConsoleEvents::TERMINATE,
                    'method' => 'handleConsoleTerminateEvent',
                    'priority' => -64,
                ],
                [
                    'event' => ConsoleEvents::ERROR,
                    'method' => 'handleConsoleErrorEvent',
                    'priority' => -64,
                ],
            ],
        ], $definition->getTags());

        $this->assertTrue($definition->getArgument(1));
    }

    public function testConsoleCommandListenerDoesNotCaptureErrorsWhenErrorListenerIsDisabled(): void
    {
        $container = $this->createContainerFromFixture('error_listener_disabled');
        $definition = $container->getDefinition(ConsoleListener::class);

        $this->assertFalse($definition->getArgument(1));
    }

    public function testMessengerListener(): void
    {
        if (!interface_exists(MessageBusInterface::class)) {
            $this->markTestSkipped('This test requires the "symfony/messenger" Composer package to be installed.');
        }

        $container = $this->createContainerFromFixture('full');
        $definition = $container->getDefinition(MessengerListener::class);

        $this->assertSame(MessengerListener::class, $definition->getClass());
        $this->assertSame([
            'kernel.event_listener' => [
                [
                    'event' => WorkerMessageFailedEvent::class,
                    'method' => 'handleWorkerMessageFailedEvent',
                    'priority' => 50,
                ],
                [
                    'event' => WorkerMessageHandledEvent::class,
                    'method' => 'handleWorkerMessageHandledEvent',
                    'priority' => 50,
                ],
            ],
        ], $definition->getTags());

        $this->assertFalse($definition->getArgument(1));
    }

    public function testMessengerListenerIsRemovedWhenDisabled(): void
    {
        $container = $this->createContainerFromFixture('messenger_listener_disabled');

        $this->assertFalse($container->hasDefinition(MessengerListener::class));
    }

    public function testRequestListener(): void
    {
        $container = $this->createContainerFromFixture('full');
        $definition = $container->getDefinition(RequestListener::class);

        $this->assertSame(RequestListener::class, $definition->getClass());
        $this->assertSame([
            'kernel.event_listener' => [
                [
                    'event' => KernelEvents::REQUEST,
                    'method' => 'handleKernelRequestEvent',
                    'priority' => 5,
                ],
                [
                    'event' => KernelEvents::CONTROLLER,
                    'method' => 'handleKernelControllerEvent',
                    'priority' => 10,
                ],
            ],
        ], $definition->getTags());
    }

    public function testSubRequestListener(): void
    {
        $container = $this->createContainerFromFixture('full');
        $definition = $container->getDefinition(SubRequestListener::class);

        $this->assertSame(SubRequestListener::class, $definition->getClass());
        $this->assertSame([
            'kernel.event_listener' => [
                [
                    'event' => KernelEvents::REQUEST,
                    'method' => 'handleKernelRequestEvent',
                    'priority' => 3,
                ],
                [
                    'event' => KernelEvents::FINISH_REQUEST,
                    'method' => 'handleKernelFinishRequestEvent',
                    'priority' => 5,
                ],
            ],
        ], $definition->getTags());
    }

    public function testClientIsCreatedFromOptions(): void
    {
        $container = $this->createContainerFromFixture('full');
        $optionsDefinition = $container->getDefinition('sentry.client.options');
        $expectedOptions = [
            'integrations' => [
                new Definition(IgnoreErrorsIntegration::class, [
                    [
                        'ignore_exceptions' => [
                            FatalError::class,
                            FatalErrorException::class,
                        ],
                    ],
                ]),
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
            'error_types' => \E_ALL,
            'max_breadcrumbs' => 1,
            'before_breadcrumb' => new Reference('App\\Sentry\\BeforeBreadcrumbCallback'),
            'in_app_exclude' => [$container->getParameter('kernel.cache_dir')],
            'in_app_include' => [$container->getParameter('kernel.project_dir')],
            'send_default_pii' => true,
            'max_value_length' => 255,
            'http_proxy' => 'proxy.example.com:8080',
            'http_timeout' => 10,
            'http_connect_timeout' => 15,
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

        $this->assertIsArray($factory);
        $this->assertInstanceOf(Definition::class, $factory[0]);
        $this->assertSame('getClient', $factory[1]);

        $methodCalls = $factory[0]->getMethodCalls();

        $this->assertCount(6, $methodCalls);
        $this->assertDefinitionMethodCallAt($methodCalls[0], 'setSdkIdentifier', [SentryBundle::SDK_IDENTIFIER]);
        $this->assertDefinitionMethodCallAt($methodCalls[1], 'setSdkVersion', [PrettyVersions::getVersion('sentry/sentry-symfony')->getPrettyVersion()]);
        $this->assertDefinitionMethodCallAt($methodCalls[2], 'setTransportFactory', [new Reference('App\\Sentry\\Transport\\TransportFactory')]);
        $this->assertDefinitionMethodCallAt($methodCalls[5], 'setLogger', [new Reference('app.logger')]);

        $this->assertSame('setSerializer', $methodCalls[3][0]);
        $this->assertInstanceOf(Definition::class, $methodCalls[3][1][0]);
        $this->assertSame(Serializer::class, $methodCalls[3][1][0]->getClass());
        $this->assertEquals($methodCalls[3][1][0]->getArgument(0), new Reference('sentry.client.options'));

        $this->assertSame('setRepresentationSerializer', $methodCalls[4][0]);
        $this->assertInstanceOf(Definition::class, $methodCalls[4][1][0]);
        $this->assertSame(RepresentationSerializer::class, $methodCalls[4][1][0]->getClass());
        $this->assertEquals($methodCalls[4][1][0]->getArgument(0), new Reference('sentry.client.options'));
    }

    public function testLoggerIsPassedToTransportFactory(): void
    {
        $container = $this->createContainerFromFixture('full');

        $transportFactoryDefinition = $container->findDefinition(TransportFactoryInterface::class);
        $logger = $transportFactoryDefinition->getArgument('$logger');

        $this->assertInstanceOf(Reference::class, $logger);
        $this->assertSame('app.logger', $logger->__toString());
    }

    public function testErrorTypesOptionIsParsedFromStringToIntegerValue(): void
    {
        $container = $this->createContainerFromFixture('error_types');
        $optionsDefinition = $container->getDefinition('sentry.client.options');

        $this->assertSame(\E_ALL & ~(\E_NOTICE | \E_STRICT | \E_DEPRECATED), $optionsDefinition->getArgument(0)['error_types']);
    }

    public function testIgnoreErrorsIntegrationIsNotAddedTwiceIfAlreadyConfigured(): void
    {
        $container = $this->createContainerFromFixture('ignore_errors_integration_overridden');
        $integrations = $container->getDefinition('sentry.client.options')->getArgument(0)['integrations'];
        $ignoreErrorsIntegrationsCount = 0;

        foreach ($integrations as $integration) {
            if ($integration instanceof Reference && IgnoreErrorsIntegration::class === (string) $integration) {
                ++$ignoreErrorsIntegrationsCount;
            }

            if ($integration instanceof Definition && IgnoreErrorsIntegration::class === $integration->getClass()) {
                ++$ignoreErrorsIntegrationsCount;
            }
        }

        $this->assertSame(1, $ignoreErrorsIntegrationsCount);
    }

    /**
     * @dataProvider dsnOptionIsSetOnClientOptionsDataProvider
     *
     * @param mixed $expectedResult
     */
    public function testDsnOptionIsSetOnClientOptions(string $fixtureFile, $expectedResult): void
    {
        $container = $this->createContainerFromFixture($fixtureFile);
        $optionsDefinition = $container->getDefinition('sentry.client.options');

        $this->assertSame(Options::class, $optionsDefinition->getClass());
        $this->assertSame($expectedResult, $optionsDefinition->getArgument(0)['dsn']);
    }

    /**
     * @return \Generator<mixed>
     */
    public function dsnOptionIsSetOnClientOptionsDataProvider(): \Generator
    {
        yield [
            'dsn_empty_string',
            '',
        ];

        yield [
            'dsn_false',
            false,
        ];

        yield [
            'dsn_null',
            null,
        ];
    }

    public function testInstrumentationIsDisabledWhenTracingIsDisabled(): void
    {
        $container = $this->createContainerFromFixture('tracing_disabled');

        $this->assertFalse($container->hasDefinition(TracingRequestListener::class));
        $this->assertFalse($container->hasDefinition(TracingSubRequestListener::class));
        $this->assertFalse($container->hasDefinition(TracingConsoleListener::class));
        $this->assertFalse($container->hasDefinition(TracingDriverMiddleware::class));
        $this->assertFalse($container->hasDefinition(ConnectionConfigurator::class));
        $this->assertFalse($container->hasDefinition(TwigTracingExtension::class));
        $this->assertFalse($container->getParameter('sentry.tracing.enabled'));
        $this->assertEmpty($container->getParameter('sentry.tracing.dbal.connections'));
    }

    public function testTracingDriverMiddlewareIsConfiguredWhenDbalTracingIsEnabled(): void
    {
        if (!class_exists(DoctrineBundle::class)) {
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('DBAL tracing support cannot be enabled because the doctrine/doctrine-bundle Composer package is not installed.');
        }

        $container = $this->createContainerFromFixture('dbal_tracing_enabled');

        $this->assertTrue($container->hasDefinition(TracingDriverMiddleware::class));
        $this->assertTrue($container->hasDefinition(ConnectionConfigurator::class));
        $this->assertNotEmpty($container->getParameter('sentry.tracing.dbal.connections'));
    }

    public function testTracingDriverMiddlewareIsRemovedWhenDbalTracingIsDisabled(): void
    {
        $container = $this->createContainerFromFixture('full');

        $this->assertFalse($container->hasDefinition(TracingDriverMiddleware::class));
        $this->assertFalse($container->hasDefinition(ConnectionConfigurator::class));
        $this->assertEmpty($container->getParameter('sentry.tracing.dbal.connections'));
    }

    public function testTwigTracingExtensionIsConfiguredWhenTwigTracingIsEnabled(): void
    {
        if (!class_exists(TwigBundle::class)) {
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('Twig tracing support cannot be enabled because the symfony/twig-bundle Composer package is not installed.');
        }

        $container = $this->createContainerFromFixture('twig_tracing_enabled');

        $this->assertTrue($container->hasDefinition(TwigTracingExtension::class));
    }

    public function testHttpClientTracingExtensionIsConfiguredWhenHttpClientTracingIsEnabled(): void
    {
        if (!class_exists(HttpClient::class)) {
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('Http client tracing support cannot be enabled because the symfony/http-client Composer package is not installed.');
        }

        $container = $this->createContainerFromFixture('http_client_tracing_enabled');

        $this->assertTrue($container->getParameter('sentry.tracing.http_client.enabled'));
    }

    public function testTwigTracingExtensionIsRemovedWhenTwigTracingIsDisabled(): void
    {
        $container = $this->createContainerFromFixture('full');

        $this->assertFalse($container->hasDefinition(TwigTracingExtension::class));
    }

    public function testConsoleTracingListenerIsConfiguredWhenTracingIsEnabled(): void
    {
        $container = $this->createContainerFromFixture('console_tracing_enabled');

        $this->assertTrue($container->hasDefinition(TracingConsoleListener::class));
        $this->assertSame(['foo:bar', 'bar:foo'], $container->getDefinition(TracingConsoleListener::class)->getArgument(1));
    }

    public function testLoggerOptionFallbackToNullLoggerIfNotSet(): void
    {
        $container = $this->createContainerFromFixture('logger_service_not_set');
        $clientDefinition = $container->findDefinition(ClientInterface::class);
        $factory = $clientDefinition->getFactory();

        $this->assertIsArray($factory);

        $methodCalls = $factory[0]->getMethodCalls();

        $this->assertDefinitionMethodCallAt($methodCalls[5], 'setLogger', [new Reference(NullLogger::class, ContainerBuilder::IGNORE_ON_INVALID_REFERENCE)]);
    }

    private function createContainerFromFixture(string $fixtureFile): ContainerBuilder
    {
        $container = new ContainerBuilder(new EnvPlaceholderParameterBag([
            'kernel.cache_dir' => __DIR__,
            'kernel.build_dir' => __DIR__,
            'kernel.project_dir' => __DIR__,
            'doctrine.default_connection' => 'default',
            'doctrine.connections' => ['default'],
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
     * @param mixed[]           $arguments
     */
    private function assertDefinitionMethodCallAt(array $methodCall, string $method, array $arguments): void
    {
        $this->assertSame($method, $methodCall[0]);
        $this->assertEquals($arguments, $methodCall[1]);
    }
}
