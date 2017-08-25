<?php

namespace Sentry\SentryBundle\Test\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\DependencyInjection\SentryExtension;
use Sentry\SentryBundle\EventListener\ExceptionListener;
use Sentry\SentryBundle\SentrySymfonyClient;
use Sentry\SentryBundle\Test\Fixtures\CustomExceptionListener;
use Sentry\SentryBundle\Test\Fixtures\InvalidExceptionListener;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class SentryExtensionTest extends TestCase
{
    const CONFIG_ROOT = 'sentry';

    public function test_that_it_uses_kernel_root_parent_as_app_path_by_default()
    {
        $container = $this->getContainer();

        $this->assertSame(
            'kernel/root/..',
            $container->getParameter('sentry.app_path')
        );
    }

    public function test_that_it_uses_app_path_value()
    {
        $container = $this->getContainer(
            [
                static::CONFIG_ROOT => [
                    'options' => ['app_path' => 'sentry/app/path'],
                ],
            ]
        );

        $options = $container->getParameter('sentry.options');
        $this->assertSame(
            'sentry/app/path',
            $options['app_path']
        );
    }

    public function test_vendor_in_default_excluded_paths()
    {
        $container = $this->getContainer();

        $options = $container->getParameter('sentry.options');
        $this->assertContains(
            'kernel/root/../vendor',
            $options['excluded_app_paths']
        );
    }

    public function test_that_it_uses_defined_class_as_client_class_by_default()
    {
        $container = $this->getContainer();

        $this->assertSame(
            SentrySymfonyClient::class,
            $container->getParameter('sentry.client')
        );
    }

    public function test_that_it_uses_client_value()
    {
        $container = $this->getContainer(
            [
                static::CONFIG_ROOT => [
                    'client' => 'clientClass',
                ],
            ]
        );

        $this->assertSame(
            'clientClass',
            $container->getParameter('sentry.client')
        );
    }

    public function test_that_it_uses_kernel_environment_as_environment_by_default()
    {
        $container = $this->getContainer();

        $options = $container->getParameter('sentry.options');
        $this->assertSame(
            'test',
            $options['environment']
        );
    }

    public function test_that_it_uses_environment_value()
    {
        $container = $this->getContainer(
            [
                static::CONFIG_ROOT => [
                    'options' => ['environment' => 'custom_env'],
                ],
            ]
        );

        $options = $container->getParameter('sentry.options');
        $this->assertSame(
            'custom_env',
            $options['environment']
        );
    }

    public function test_that_it_uses_null_as_dsn_default_value()
    {
        $container = $this->getContainer();

        $this->assertNull(
            $container->getParameter('sentry.dsn')
        );
    }

    /**
     * @dataProvider emptyDsnValueProvider
     */
    public function test_that_it_ignores_empty_dsn_value($emptyDsn)
    {
        $container = $this->getContainer(
            [
                static::CONFIG_ROOT => [
                    'dsn' => $emptyDsn,
                ],
            ]
        );

        $this->assertNull($container->getParameter('sentry.dsn'));
    }

    public function emptyDsnValueProvider()
    {
        return [
            [null],
            [''],
            [' '],
            ['    '],
        ];
    }

    public function test_that_it_uses_dsn_value()
    {
        $container = $this->getContainer(
            [
                static::CONFIG_ROOT => [
                    'dsn' => 'custom_dsn',
                ],
            ]
        );

        $this->assertSame(
            'custom_dsn',
            $container->getParameter('sentry.dsn')
        );
    }

    public function test_that_it_uses_http_proxy_value()
    {
        $container = $this->getContainer(
            [
                static::CONFIG_ROOT => [
                    'options' => [
                        'http_proxy' => 'http://user:password@host:port',
                    ],
                ],
            ]
        );

        $options = $container->getParameter('sentry.options');

        $this->assertSame(
            'http://user:password@host:port',
            $options['http_proxy']
        );
    }

    public function test_that_it_has_default_priority_values()
    {
        $container = $this->getContainer();

        $this->assertTrue($container->hasParameter('sentry.listener_priorities'));

        $priorities = $container->getParameter('sentry.listener_priorities');
        $this->assertInternalType('array', $priorities);

        $this->assertSame(0, $priorities['request']);
        $this->assertSame(0, $priorities['kernel_exception']);
        $this->assertSame(0, $priorities['console_exception']);
    }

    public function test_that_it_is_invalid_if_exception_listener_fails_to_implement_required_interface()
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->getContainer(
            [
                static::CONFIG_ROOT => [
                    'exception_listener' => 'Some\Invalid\Class',
                ],
            ]
        );
    }

    public function test_that_it_uses_defined_class_as_exception_listener_class_by_default()
    {
        $container = $this->getContainer();

        $this->assertSame(
            ExceptionListener::class,
            $container->getParameter('sentry.exception_listener')
        );
    }

    public function test_that_it_uses_exception_listener_value()
    {
        $class = CustomExceptionListener::class;
        $container = $this->getContainer(
            [
                static::CONFIG_ROOT => [
                    'exception_listener' => $class,
                ],
            ]
        );

        $this->assertSame(
            $class,
            $container->getParameter('sentry.exception_listener')
        );
    }

    public function test_that_it_uses_array_with_http_exception_as_skipped_capture_by_default()
    {
        $container = $this->getContainer();

        $this->assertSame(
            [
                HttpExceptionInterface::class,
            ],
            $container->getParameter('sentry.skip_capture')
        );
    }

    public function test_that_it_uses_skipped_capture_value()
    {
        $container = $this->getContainer(
            [
                static::CONFIG_ROOT => [
                    'skip_capture' => [
                        'classA',
                        'classB',
                    ],
                ],
            ]
        );

        $this->assertSame(
            ['classA', 'classB'],
            $container->getParameter('sentry.skip_capture')
        );
    }

    public function test_that_it_uses_null_as_release_by_default()
    {
        $container = $this->getContainer();

        $options = $container->getParameter('sentry.options');
        $this->assertNull(
            $options['release']
        );
    }

    public function test_that_it_uses_release_value()
    {
        $container = $this->getContainer(
            [
                static::CONFIG_ROOT => [
                    'options' => ['release' => '1.0'],
                ],
            ]
        );

        $options = $container->getParameter('sentry.options');
        $this->assertSame(
            '1.0',
            $options['release']
        );
    }

    public function test_that_it_uses_array_with_kernel_parent_as_prefix_by_default()
    {
        $container = $this->getContainer();

        $options = $container->getParameter('sentry.options');
        $this->assertSame(
            ['kernel/root/..'],
            $options['prefixes']
        );
    }

    public function test_that_it_uses_prefixes_value()
    {
        $container = $this->getContainer(
            [
                static::CONFIG_ROOT => [
                    'options' => [
                        'prefixes' => [
                            'dirA',
                            'dirB',
                        ],
                    ],
                ],
            ]
        );

        $options = $container->getParameter('sentry.options');
        $this->assertSame(
            ['dirA', 'dirB'],
            $options['prefixes']
        );
    }

    public function test_that_it_has_sentry_client_service_and_it_defaults_to_symfony_client()
    {
        $client = $this->getContainer()->get('sentry.client');
        $this->assertInstanceOf(SentrySymfonyClient::class, $client);
    }

    public function test_that_it_has_sentry_exception_listener_and_it_defaults_to_default_exception_listener()
    {
        $client = $this->getContainer()->get('sentry.exception_listener');
        $this->assertInstanceOf(ExceptionListener::class, $client);
    }

    public function test_that_it_has_proper_event_listener_tags_for_exception_listener()
    {
        $containerBuilder = new ContainerBuilder();
        $extension = new SentryExtension();
        $extension->load([], $containerBuilder);

        $definition = $containerBuilder->getDefinition('sentry.exception_listener');
        $tags = $definition->getTag('kernel.event_listener');

        $this->assertSame(
            [
                [
                    'event' => 'kernel.request',
                    'method' => 'onKernelRequest',
                    'priority' => '%sentry.listener_priorities.request%',
                ],
                [
                    'event' => 'kernel.exception',
                    'method' => 'onKernelException',
                    'priority' => '%sentry.listener_priorities.kernel_exception%',
                ],
                ['event' => 'console.command', 'method' => 'onConsoleCommand'],
                [
                    'event' => 'console.exception',
                    'method' => 'onConsoleException',
                    'priority' => '%sentry.listener_priorities.console_exception%',
                ],
            ],
            $tags
        );
    }

    public function test_that_it_sets_all_options()
    {
        $config = [
            'options' => [
                'logger' => 'logger',
                'server' => 'server',
                'secret_key' => 'secret_key',
                'public_key' => 'public_key',
                'project' => 'project',
                'auto_log_stacks' => true,
                'name' => 'name',
                'site' => 'site',
                'tags' => [
                    'tag1' => 'tagname',
                    'tag2' => 'tagename 2',
                ],
                'release' => 'release',
                'environment' => 'environment',
                'sample_rate' => 0.9,
                'trace' => false,
                'timeout' => 1,
                'message_limit' => 512,
                'exclude' => [
                    'test1',
                    'test2',
                ],
                'http_proxy' => 'http_proxy',
                'extra' => [
                    'extra1' => 'extra1',
                    'extra2' => 'extra2',
                ],
                'curl_method' => 'curl_method',
                'curl_path' => 'curl_path',
                'curl_ipv4' => false,
                'ca_cert' => 'ca_cert',
                'verify_ssl' => false,
                'curl_ssl_version' => 'curl_ssl_version',
                'trust_x_forwarded_proto' => true,
                'mb_detect_order' => 'mb_detect_order',
                'error_types' => 'E_ALL & ~E_DEPRECATED & ~E_NOTICE',
                'app_path' => 'app_path',
                'excluded_app_paths' => ['excluded_app_path1', 'excluded_app_path2'],
                'prefixes' => ['prefix1', 'prefix2'],
                'install_default_breadcrumb_handlers' => false,
                'install_shutdown_handler' => false,
                'processors' => ['processor1', 'processor2'],
                'processorOptions' => [
                    'processorOption1' => 'asasdf',
                ],
            ],
        ];

        $container = $this->getContainer([static::CONFIG_ROOT => $config]);

        $options = $container->getParameter('sentry.options');
        $this->assertSame(
            $config['options'],
            $options
        );
    }

    private function getContainer(array $options = [])
    {
        $containerBuilder = new ContainerBuilder();
        $containerBuilder->setParameter('kernel.root_dir', 'kernel/root');
        $containerBuilder->setParameter('kernel.environment', 'test');

        $mockEventDispatcher = $this
            ->createMock(EventDispatcherInterface::class);

        $containerBuilder->set('event_dispatcher', $mockEventDispatcher);

        $extension = new SentryExtension();
        $extension->load($options, $containerBuilder);

        $containerBuilder->compile();

        return $containerBuilder;
    }
}
