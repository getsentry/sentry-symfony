<?php

namespace Sentry\SentryBundle\Test\DependencyInjection;

use Jean85\PrettyVersions;
use Monolog\Logger as MonologLogger;
use Prophecy\Argument;
use Sentry\Breadcrumb;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\Integration\ErrorListenerIntegration;
use Sentry\Integration\ExceptionListenerIntegration;
use Sentry\Integration\IntegrationInterface;
use Sentry\Monolog\Handler;
use Sentry\Options;
use Sentry\SentryBundle\DependencyInjection\SentryExtension;
use Sentry\SentryBundle\EventListener\ErrorListener;
use Sentry\SentryBundle\Test\BaseTestCase;
use Sentry\SentrySdk;
use Sentry\Severity;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class SentryExtensionTest extends BaseTestCase
{
    private const OPTIONS_TEST_PUBLIC_ALIAS = 'sentry.options.public_alias';
    private const ERROR_LISTENER_TEST_PUBLIC_ALIAS = 'sentry.error_listener.public_alias';
    private const MONOLOG_HANDLER_TEST_PUBLIC_ALIAS = 'sentry.monolog_handler.public_alias';

    public function testDataProviderIsMappingTheRightNumberOfOptions(): void
    {
        $providerData = $this->optionsValueProvider();
        $supportedOptions = \array_unique(\array_column($providerData, 0));

        // subtracted one is `integration`, which cannot be tested with the provider
        $expectedCount = $this->getSupportedOptionsCount();
        --$expectedCount; // excluded_exceptions is remapped to the new IgnoreErrorsIntegration

        if (PrettyVersions::getVersion('sentry/sentry')->getPrettyVersion() === '2.0.0') {
            --$expectedCount;
        }

        $this->assertCount(
            $expectedCount,
            $supportedOptions,
            'Provider for configuration options mismatch: ' . PHP_EOL . print_r($supportedOptions, true)
        );
    }

    public function testOptionsDefaultValues(): void
    {
        $container = $this->getContainer();
        $options = $this->getOptionsFrom($container);

        $vendorDir = '/dir/project/root/vendor';
        $this->assertContains('/dir/project/root/src', $options->getInAppIncludedPaths());

        $this->assertNull($options->getDsn());
        $this->assertSame('test', $options->getEnvironment());
        $this->assertSame([realpath('./var/cache'), $vendorDir], $options->getInAppExcludedPaths());

        $this->assertSame(1, $container->getParameter('sentry.listener_priorities.request'));
        $this->assertSame(1, $container->getParameter('sentry.listener_priorities.sub_request'));
        $this->assertSame(1, $container->getParameter('sentry.listener_priorities.console'));
    }

    public function testListenerPriorities(): void
    {
        $container = $this->getContainer([
            'listener_priorities' => [
                'request' => 123,
                'sub_request' => 456,
                'console' => 789,
            ],
        ]);

        $this->assertSame(123, $container->getParameter('sentry.listener_priorities.request'));
        $this->assertSame(456, $container->getParameter('sentry.listener_priorities.sub_request'));
        $this->assertSame(789, $container->getParameter('sentry.listener_priorities.console'));
    }

    /**
     * @dataProvider optionsValueProvider
     */
    public function testValuesArePassedToOptions(string $name, $value, string $getter = null): void
    {
        if (null === $getter) {
            $getter = 'get' . str_replace('_', '', ucwords($name, '_'));
        }

        $this->assertTrue(method_exists(Options::class, $getter), 'Bad data provider, wrong getter: ' . $getter);

        $container = $this->getContainer(
            [
                'options' => [$name => $value],
            ]
        );

        $this->assertSame(
            $value,
            $this->getOptionsFrom($container)->$getter()
        );

        $defaultContainer = $this->getContainer();
        $this->assertNotEquals(
            $this->getOptionsFrom($defaultContainer)->$getter(),
            $this->getOptionsFrom($container)->$getter(),
            'Bad data provider: value is same as default'
        );
    }

    public function optionsValueProvider(): array
    {
        $options = [
            ['attach_stacktrace', true, 'shouldAttachStacktrace'],
            ['before_breadcrumb', __NAMESPACE__ . '\mockBeforeBreadcrumb', 'getBeforeBreadcrumbCallback'],
            ['before_send', __NAMESPACE__ . '\mockBeforeSend', 'getBeforeSendCallback'],
            [
                'class_serializers',
                [
                    self::class => __NAMESPACE__ . '\mockClassSerializer',
                ],
                'getClassSerializers',
            ],
            ['context_lines', 1],
            ['default_integrations', false, 'hasDefaultIntegrations'],
            ['enable_compression', false, 'isCompressionEnabled'],
            ['environment', 'staging'],
            ['error_types', E_ALL & ~E_NOTICE],
            ['in_app_include', ['/some/path'], 'getInAppIncludedPaths'],
            ['in_app_exclude', ['/some/path'], 'getInAppExcludedPaths'],
            ['http_proxy', '1.2.3.4'],
            ['logger', 'sentry-logger'],
            ['max_breadcrumbs', 15],
            ['max_request_body_size', 'always'],
            ['max_value_length', 1000],
            ['prefixes', ['/some/path/prefix/']],
            ['project_root', '/some/project/'],
            ['release', 'abc0123'],
            ['sample_rate', 0.5],
            ['send_attempts', 2],
            ['send_default_pii', true, 'shouldSendDefaultPii'],
            ['server_name', 'server.example.com'],
            ['tags', ['tag-name' => 'tag-value']],
        ];

        if (PrettyVersions::getVersion('sentry/sentry')->getPrettyVersion() !== '2.0.0') {
            $options[] = ['capture_silenced_errors', true, 'shouldCaptureSilencedErrors'];
        }

        return $options;
    }

    public function testErrorTypesAreParsed(): void
    {
        $container = $this->getContainer(['options' => ['error_types' => 'E_ALL & ~E_NOTICE']]);

        $this->assertSame(E_ALL & ~E_NOTICE, $this->getOptionsFrom($container)->getErrorTypes());

        $defaultContainer = $this->getContainer();
        $this->assertNotEquals(
            $this->getOptionsFrom($defaultContainer)->getErrorTypes(),
            $this->getOptionsFrom($container)->getErrorTypes(),
            'Bad data: value is same as default'
        );
    }

    /**
     * @dataProvider emptyDsnValueProvider
     */
    public function test_that_it_ignores_empty_dsn_value($emptyDsn): void
    {
        $container = $this->getContainer(
            [
                'dsn' => $emptyDsn,
            ]
        );

        $this->assertNull($this->getOptionsFrom($container)->getDsn());
    }

    public function emptyDsnValueProvider(): array
    {
        return [
            [null],
            [''],
            [' '],
            ['    '],
        ];
    }

    public function testBeforeSendUsingServiceDefinition(): void
    {
        $container = $this->getContainer([
            'options' => [
                'before_send' => '@callable_mock',
            ],
        ]);

        $beforeSendCallback = $this->getOptionsFrom($container)->getBeforeSendCallback();
        $this->assertIsCallable($beforeSendCallback);
        $defaultOptions = $this->getOptionsFrom($this->getContainer());
        $this->assertNotEquals(
            $defaultOptions->getBeforeSendCallback(),
            $beforeSendCallback,
            'before_send closure has not been replaced, is the default one'
        );
        $this->assertEquals(
            CallbackMock::createCallback(),
            $beforeSendCallback
        );
    }

    /**
     * @dataProvider scalarCallableDataProvider
     */
    public function testBeforeSendUsingScalarCallable($scalarCallable): void
    {
        $container = $this->getContainer([
            'options' => [
                'before_send' => $scalarCallable,
            ],
        ]);

        $beforeSendCallback = $this->getOptionsFrom($container)->getBeforeSendCallback();
        $this->assertIsCallable($beforeSendCallback);
        $defaultOptions = $this->getOptionsFrom($this->getContainer());
        $this->assertNotEquals(
            $defaultOptions->getBeforeSendCallback(),
            $beforeSendCallback,
            'before_send closure has not been replaced, is the default one'
        );
        $this->assertEquals(
            $scalarCallable,
            $beforeSendCallback
        );
    }

    public function testBeforeSendWithInvalidServiceReference(): void
    {
        $container = $this->getContainer([
            'options' => [
                'before_send' => '@event_dispatcher',
            ],
        ]);

        $this->expectException(\TypeError::class);

        $this->getOptionsFrom($container)->getBeforeSendCallback();
    }

    public function testBeforeBreadcrumbUsingServiceDefinition(): void
    {
        $container = $this->getContainer([
            'options' => [
                'before_breadcrumb' => '@callable_mock',
            ],
        ]);

        $beforeBreadcrumbCallback = $this->getOptionsFrom($container)->getBeforeBreadcrumbCallback();
        $this->assertIsCallable($beforeBreadcrumbCallback);
        $defaultOptions = $this->getOptionsFrom($this->getContainer());
        $this->assertNotEquals(
            $defaultOptions->getBeforeBreadcrumbCallback(),
            $beforeBreadcrumbCallback,
            'before_breadcrumb closure has not been replaced, is the default one'
        );
        $this->assertEquals(
            CallbackMock::createCallback(),
            $beforeBreadcrumbCallback
        );
    }

    /**
     * @dataProvider scalarCallableDataProvider
     */
    public function testBeforeBreadcrumbUsingScalarCallable($scalarCallable): void
    {
        $container = $this->getContainer([
            'options' => [
                'before_breadcrumb' => $scalarCallable,
            ],
        ]);

        $beforeBreadcrumbCallback = $this->getOptionsFrom($container)->getBeforeBreadcrumbCallback();
        $this->assertIsCallable($beforeBreadcrumbCallback);
        $defaultOptions = $this->getOptionsFrom($this->getContainer());
        $this->assertNotEquals(
            $defaultOptions->getBeforeBreadcrumbCallback(),
            $beforeBreadcrumbCallback,
            'before_breadcrumb closure has not been replaced, is the default one'
        );
        $this->assertEquals(
            $scalarCallable,
            $beforeBreadcrumbCallback
        );
    }

    public function scalarCallableDataProvider(): array
    {
        return [
            [[CallbackMock::class, 'callback']],
            [CallbackMock::class . '::callback'],
            [__NAMESPACE__ . '\mockBeforeSend'],
        ];
    }

    public function testBeforeBreadcrumbWithInvalidServiceReference(): void
    {
        $container = $this->getContainer([
            'options' => [
                'before_breadcrumb' => '@event_dispatcher',
            ],
        ]);

        $this->expectException(\TypeError::class);

        $this->getOptionsFrom($container)->getBeforeBreadcrumbCallback();
    }

    public function testIntegrations(): void
    {
        $container = $this->getContainer([
            'options' => [
                'integrations' => ['@integration_mock'],
            ],
        ]);

        $integrations = $this->getOptionsFrom($container)->getIntegrations();
        $this->assertNotEmpty($integrations);

        $found = false;
        foreach ($integrations as $integration) {
            if ($integration instanceof ErrorListenerIntegration) {
                $this->fail('Should not have ErrorListenerIntegration registered');
            }

            if ($integration instanceof ExceptionListenerIntegration) {
                $this->fail('Should not have ExceptionListenerIntegration registered');
            }

            if ($integration instanceof IntegrationMock) {
                $found = true;
            }
        }

        $this->assertTrue($found, 'No IntegrationMock found in final integrations enabled');
    }

    /**
     * @dataProvider errorListenerConfigurationProvider
     */
    public function testErrorListenerIsRegistered(bool $registerErrorListener): void
    {
        $container = $this->getContainer([
            'register_error_listener' => $registerErrorListener,
        ]);

        $this->assertSame($registerErrorListener, $container->has(self::ERROR_LISTENER_TEST_PUBLIC_ALIAS));
    }

    public function errorListenerConfigurationProvider(): array
    {
        return [
            [true],
            [false],
        ];
    }

    /**
     * @dataProvider monologHandlerConfigurationProvider
     */
    public function testMonologHandlerIsConfiguredProperly($level, bool $bubble, int $monologLevel): void
    {
        $this->expectExceptionIfMonologHandlerDoesNotExist();

        $container = $this->getContainer([
            'monolog' => [
                'error_handler' => [
                    'enabled' => true,
                    'level' => $level,
                    'bubble' => $bubble,
                ],
            ],
        ]);

        $this->assertTrue($container->has(self::MONOLOG_HANDLER_TEST_PUBLIC_ALIAS));

        /** @var Handler $handler */
        $handler = $container->get(self::MONOLOG_HANDLER_TEST_PUBLIC_ALIAS);
        $this->assertEquals($monologLevel, $handler->getLevel());
        $this->assertEquals($bubble, $handler->getBubble());
    }

    public function monologHandlerConfigurationProvider(): array
    {
        return [
            ['DEBUG', true, MonologLogger::DEBUG],
            ['debug', false, MonologLogger::DEBUG],
            ['ERROR', true, MonologLogger::ERROR],
            ['error', false, MonologLogger::ERROR],
            [MonologLogger::ALERT, true, MonologLogger::ALERT],
            [MonologLogger::EMERGENCY, false, MonologLogger::EMERGENCY],
        ];
    }

    public function testMonologHandlerIsNotRegistered(): void
    {
        $container = $this->getContainer([
            'monolog' => [
                'error_handler' => [
                    'enabled' => false,
                ],
            ],
        ]);

        $this->assertFalse($container->has(self::MONOLOG_HANDLER_TEST_PUBLIC_ALIAS));
    }

    private function getContainer(array $configuration = []): Container
    {
        $containerBuilder = new ContainerBuilder();
        $containerBuilder->setParameter('kernel.cache_dir', 'var/cache');
        $containerBuilder->setParameter('kernel.project_dir', '/dir/project/root');
        $containerBuilder->setParameter('kernel.environment', 'test');

        $mockEventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $mockRequestStack = $this->createMock(RequestStack::class);

        $containerBuilder->set('request_stack', $mockRequestStack);
        $containerBuilder->set('event_dispatcher', $mockEventDispatcher);
        $containerBuilder->setAlias(self::OPTIONS_TEST_PUBLIC_ALIAS, new Alias(Options::class, true));

        $beforeSend = new Definition('callable');
        $beforeSend->setFactory([CallbackMock::class, 'createCallback']);
        $containerBuilder->setDefinition('callable_mock', $beforeSend);

        $integration = new Definition(IntegrationMock::class);
        $containerBuilder->setDefinition('integration_mock', $integration);

        $extension = new SentryExtension();
        $extension->load(['sentry' => $configuration], $containerBuilder);

        $client = new Definition(ClientMock::class);
        $containerBuilder->setDefinition(ClientInterface::class, $client);

        if ($containerBuilder->hasDefinition(ErrorListener::class)) {
            $containerBuilder->setAlias(self::ERROR_LISTENER_TEST_PUBLIC_ALIAS, new Alias(ErrorListener::class, true));
        }

        if ($containerBuilder->hasDefinition(Handler::class)) {
            $containerBuilder->setAlias(self::MONOLOG_HANDLER_TEST_PUBLIC_ALIAS, new Alias(Handler::class, true));
        }

        $hub = $this->prophesize(HubInterface::class);
        $hub->bindClient(Argument::type(ClientMock::class));
        SentrySdk::setCurrentHub($hub->reveal());

        $containerBuilder->compile();

        return $containerBuilder;
    }

    private function getOptionsFrom(Container $container): Options
    {
        $this->assertTrue(
            $container->has(self::OPTIONS_TEST_PUBLIC_ALIAS),
            'Options (or public alias) missing from container!'
        );

        $options = $container->get(self::OPTIONS_TEST_PUBLIC_ALIAS);
        $this->assertInstanceOf(Options::class, $options);

        return $options;
    }

    private function expectExceptionIfMonologHandlerDoesNotExist(): void
    {
        if (! class_exists(Handler::class)) {
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage(
                sprintf('Missing class "%s", try updating "sentry/sentry" to a newer version.', Handler::class)
            );
        }
    }
}

function mockBeforeSend(Event $event): ?Event
{
    return null;
}

function mockBeforeBreadcrumb(Breadcrumb $breadcrumb): ?Breadcrumb
{
    return null;
}

function mockClassSerializer($object)
{
    return ['value' => 'serialized_class'];
}

class CallbackMock
{
    public static function callback()
    {
        return null;
    }

    public static function createCallback(): callable
    {
        return [new self(), 'callback'];
    }
}

class IntegrationMock implements IntegrationInterface
{
    public function setupOnce(): void
    {
    }
}

class ClientMock implements ClientInterface
{
    public function getOptions(): Options
    {
        return new Options();
    }

    public function captureMessage(string $message, ?Severity $level = null, ?Scope $scope = null): ?string
    {
        return null;
    }

    public function captureException(\Throwable $exception, ?Scope $scope = null): ?string
    {
        return null;
    }

    public function captureLastError(?Scope $scope = null): ?string
    {
        return null;
    }

    public function captureEvent(array $payload, ?Scope $scope = null): ?string
    {
        return null;
    }

    public function getIntegration(string $className): ?IntegrationInterface
    {
        return null;
    }
}
