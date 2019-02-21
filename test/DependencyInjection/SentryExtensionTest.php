<?php

namespace Sentry\SentryBundle\Test\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Sentry\Options;
use Sentry\SentryBundle\DependencyInjection\SentryExtension;
use Sentry\SentryBundle\EventListener\ConsoleListener;
use Sentry\SentryBundle\EventListener\RequestListener;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Kernel;

class SentryExtensionTest extends TestCase
{
    private const REQUEST_LISTENER_TEST_PUBLIC_ALIAS = 'sentry.request_listener.public_alias';
    private const CONSOLE_LISTENER_TEST_PUBLIC_ALIAS = 'sentry.console_listener.public_alias';
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
            ['context_lines', 1],
            ['default_integrations', false, 'hasDefaultIntegrations'],
            ['enable_compression', false, 'isCompressionEnabled'],
            ['environment', 'staging'],
            ['error_types', E_ALL & ~E_NOTICE],
            ['in_app_exclude', ['/some/path'], 'getInAppExcludedPaths'],
            ['excluded_exceptions', [\Throwable::class]],
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
        $containerBuilder->setAlias(self::REQUEST_LISTENER_TEST_PUBLIC_ALIAS, new Alias(RequestListener::class, true));
        $containerBuilder->setAlias(self::CONSOLE_LISTENER_TEST_PUBLIC_ALIAS, new Alias(ConsoleListener::class, true));

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
