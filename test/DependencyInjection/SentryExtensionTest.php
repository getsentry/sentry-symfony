<?php

namespace Sentry\SentryBundle\Test\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\Options;
use Sentry\SentryBundle\DependencyInjection\SentryExtension;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Kernel;

class SentryExtensionTest extends TestCase
{
    private const OPTIONS_TEST_PUBLIC_ALIAS = 'sentry.options.public_alias';

    public function testDataProviderIsMappingTheRightNumberOfOptions(): void
    {
        $providerData = $this->optionsValueProvider();
        $supportedOptions = \array_unique(\array_column($providerData, 0));

        $this->assertCount(
            ConfigurationTest::SUPPORTED_SENTRY_OPTIONS_COUNT,
            $supportedOptions,
            'Provider for configuration options mismatch: ' . PHP_EOL . print_r($supportedOptions, true)
        );
    }

    public function testOptionsDefaultValues(): void
    {
        $container = $this->getContainer();
        $options = $this->getOptionsFrom($container);

        if (method_exists(Kernel::class, 'getProjectDir')) {
            $vendorDir = '/dir/project/root/vendor';
            $this->assertSame('/dir/project/root', $options->getProjectRoot());
        } else {
            $vendorDir = 'kernel/root/../vendor';
            $this->assertSame('kernel/root/..', $options->getProjectRoot());
        }

        $this->assertNull($options->getDsn());
        $this->assertSame('test', $options->getEnvironment());
        $this->assertSame(['var/cache', $vendorDir], $options->getInAppExcludedPaths());

        $this->assertSame(1, $container->getParameter('sentry.listener_priorities.request'));
        $this->assertSame(1, $container->getParameter('sentry.listener_priorities.console'));
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
        return [
            ['attach_stacktrace', true, 'shouldAttachStacktrace'],
            ['before_breadcrumb', __NAMESPACE__ . '\mockBeforeBreadcrumb', 'getBeforeBreadcrumbCallback'],
            ['before_send', __NAMESPACE__ . '\mockBeforeSend', 'getBeforeSendCallback'],
            ['context_lines', 1],
            ['default_integrations', false, 'hasDefaultIntegrations'],
            ['enable_compression', false, 'isCompressionEnabled'],
            ['environment', 'staging'],
            ['error_types', E_ALL & ~E_NOTICE],
            ['in_app_exclude', ['/some/path'], 'getInAppExcludedPaths'],
            ['excluded_exceptions', [\Throwable::class]],
            ['http_proxy', '1.2.3.4'],
            ['logger', 'sentry-logger'],
            ['max_breadcrumbs', 15],
            ['prefixes', ['/some/path/prefix/']],
            ['project_root', '/some/project/'],
            ['release', 'abc0123'],
            ['sample_rate', 0.5],
            ['send_attempts', 2],
            ['send_default_pii', true, 'shouldSendDefaultPii'],
            ['server_name', 'server.example.com'],
            ['tags', ['tag-name' => 'tag-value']],
        ];
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

    private function getContainer(array $configuration = []): Container
    {
        $containerBuilder = new ContainerBuilder();
        $containerBuilder->setParameter('kernel.cache_dir', 'var/cache');
        $containerBuilder->setParameter('kernel.root_dir', 'kernel/root');
        if (method_exists(Kernel::class, 'getProjectDir')) {
            $containerBuilder->setParameter('kernel.project_dir', '/dir/project/root');
        }
        $containerBuilder->setParameter('kernel.environment', 'test');

        $mockEventDispatcher = $this
            ->createMock(EventDispatcherInterface::class);

        $mockRequestStack = $this
            ->createMock(RequestStack::class);

        $containerBuilder->set('request_stack', $mockRequestStack);
        $containerBuilder->set('event_dispatcher', $mockEventDispatcher);
        $containerBuilder->setAlias(self::OPTIONS_TEST_PUBLIC_ALIAS, new Alias(Options::class, true));

        $beforeSend = new Definition('callable');
        $beforeSend->setFactory([CallbackMock::class, 'createCallback']);
        $containerBuilder->setDefinition('callable_mock', $beforeSend);

        $extension = new SentryExtension();
        $extension->load(['sentry' => $configuration], $containerBuilder);

        $containerBuilder->compile();

        return $containerBuilder;
    }

    private function getOptionsFrom(Container $container): Options
    {
        $this->assertTrue($container->has(self::OPTIONS_TEST_PUBLIC_ALIAS), 'Options (or public alias) missing from container!');

        $options = $container->get(self::OPTIONS_TEST_PUBLIC_ALIAS);
        $this->assertInstanceOf(Options::class, $options);

        return $options;
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
