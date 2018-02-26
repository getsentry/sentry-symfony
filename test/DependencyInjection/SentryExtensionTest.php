<?php

namespace Sentry\SentryBundle\Test\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\DependencyInjection\SentryExtension;
use Sentry\SentryBundle\EventListener\ExceptionListener;
use Sentry\SentryBundle\SentrySymfonyClient;
use Sentry\SentryBundle\Test\Fixtures\CustomExceptionListener;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class SentryExtensionTest extends TestCase
{
    const SUPPORTED_SENTRY_OPTIONS_COUNT = 34;

    public function test_that_configuration_uses_the_right_default_values()
    {
        $container = $this->getContainer();

        $this->assertSame('kernel/root/..', $container->getParameter('sentry.app_path'));
        $this->assertSame(SentrySymfonyClient::class, $container->getParameter('sentry.client'));
        $this->assertNull($container->getParameter('sentry.dsn'));
        $this->assertSame(ExceptionListener::class, $container->getParameter('sentry.exception_listener'));
        $this->assertSame([HttpExceptionInterface::class], $container->getParameter('sentry.skip_capture'));

        $priorities = $container->getParameter('sentry.listener_priorities');
        $this->assertInternalType('array', $priorities);
        $this->assertSame(0, $priorities['request']);
        $this->assertSame(0, $priorities['kernel_exception']);
        $this->assertSame(0, $priorities['console_exception']);

        $options = $container->getParameter('sentry.options');
        $this->assertCount(self::SUPPORTED_SENTRY_OPTIONS_COUNT, $options);
        // same order as in Configuration class
        $this->assertSame('php', $options['logger']);
        $this->assertNull($options['server']);
        $this->assertNull($options['secret_key']);
        $this->assertNull($options['public_key']);
        $this->assertSame(1, $options['project']);
        $this->assertFalse($options['auto_log_stacks']);
        $this->assertSame(\Raven_Compat::gethostname(), $options['name']);
        $this->assertNull($options['site']);
        $this->assertSame([], $options['tags']);
        $this->assertNull($options['release']);
        $this->assertSame('test', $options['environment']);
        $this->assertSame(1, $options['sample_rate']);
        $this->assertTrue($options['trace']);
        $this->assertSame(\Raven_Client::MESSAGE_LIMIT, $options['message_limit']);
        $this->assertSame([], $options['exclude']);
        $this->assertNull($options['http_proxy']);
        $this->assertSame([], $options['extra']);
        $this->assertSame('sync', $options['curl_method']);
        $this->assertSame('curl', $options['curl_path']);
        $this->assertTrue($options['curl_ipv4']);
        $this->assertNull($options['ca_cert']);
        $this->assertTrue($options['verify_ssl']);
        $this->assertNull($options['curl_ssl_version']);
        $this->assertFalse($options['trust_x_forwarded_proto']);
        $this->assertNull($options['mb_detect_order']);
        $this->assertNull($options['error_types']);
        $this->assertSame('kernel/root/..', $options['app_path']);
        $this->assertContains('kernel/root/../vendor', $options['excluded_app_paths']);
        $this->assertContains('kernel/root/../app/cache', $options['excluded_app_paths']);
        $this->assertContains('kernel/root/../var/cache', $options['excluded_app_paths']);
        $this->assertSame(['kernel/root/..'], $options['prefixes']);
        $this->assertTrue($options['install_default_breadcrumb_handlers']);
        $this->assertTrue($options['install_shutdown_handler']);
        $this->assertSame([\Raven_Processor_SanitizeDataProcessor::class], $options['processors']);
        $this->assertSame([], $options['processorOptions']);
    }

    public function test_that_it_uses_app_path_value()
    {
        $container = $this->getContainer(
            [
                'options' => ['app_path' => 'sentry/app/path'],
            ]
        );

        $options = $container->getParameter('sentry.options');
        $this->assertSame(
            'sentry/app/path',
            $options['app_path']
        );
    }

    public function test_that_it_uses_client_value()
    {
        $container = $this->getContainer(
            [
                'client' => 'clientClass',
            ]
        );

        $this->assertSame(
            'clientClass',
            $container->getParameter('sentry.client')
        );
    }

    public function test_that_it_uses_environment_value()
    {
        $container = $this->getContainer(
            [
                'options' => ['environment' => 'custom_env'],
            ]
        );

        $options = $container->getParameter('sentry.options');
        $this->assertSame(
            'custom_env',
            $options['environment']
        );
    }

    /**
     * @dataProvider emptyDsnValueProvider
     */
    public function test_that_it_ignores_empty_dsn_value($emptyDsn)
    {
        $container = $this->getContainer(
            [
                'dsn' => $emptyDsn,
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
                'dsn' => 'custom_dsn',
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
                'options' => [
                    'http_proxy' => 'http://user:password@host:port',
                ],
            ]
        );

        $options = $container->getParameter('sentry.options');

        $this->assertSame(
            'http://user:password@host:port',
            $options['http_proxy']
        );
    }

    public function test_that_it_is_invalid_if_exception_listener_fails_to_implement_required_interface()
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->getContainer(
            [
                'exception_listener' => 'Some\Invalid\Class',
            ]
        );
    }

    public function test_that_it_uses_exception_listener_value()
    {
        $class = CustomExceptionListener::class;
        $container = $this->getContainer(
            [
                'exception_listener' => $class,
            ]
        );

        $this->assertSame(
            $class,
            $container->getParameter('sentry.exception_listener')
        );
    }

    public function test_that_it_uses_skipped_capture_value()
    {
        $container = $this->getContainer(
            [
                'skip_capture' => [
                    'classA',
                    'classB',
                ],
            ]
        );

        $this->assertSame(
            ['classA', 'classB'],
            $container->getParameter('sentry.skip_capture')
        );
    }

    public function test_that_it_uses_release_value()
    {
        $container = $this->getContainer(
            [
                'options' => ['release' => '1.0'],
            ]
        );

        $options = $container->getParameter('sentry.options');
        $this->assertSame(
            '1.0',
            $options['release']
        );
    }

    public function test_that_it_uses_prefixes_value()
    {
        $container = $this->getContainer(
            [
                'options' => [
                    'prefixes' => [
                        'dirA',
                        'dirB',
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

    public function test_that_it_sets_all_sentry_options()
    {
        $options = [
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
                'processor1' => [
                    'processorOption1' => 'asasdf',
                ],
                'processor2' => [
                    'processorOption2' => 'asasdf',
                ],
            ],
        ];

        $this->assertCount(self::SUPPORTED_SENTRY_OPTIONS_COUNT, $options);
        $defaultOptions = $this->getContainer()->getParameter('sentry.options');
        foreach ($options as $name => $value) {
            $this->assertNotEquals($defaultOptions[$name], $value, 'Test precondition failed: using default value for ' . $name);
        }

        $container = $this->getContainer(['options' => $options]);

        $this->assertSame($options, $container->getParameter('sentry.options'));
    }

    /**
     * @param array $configuration
     * @return ContainerBuilder
     */
    private function getContainer(array $configuration = [])
    {
        $containerBuilder = new ContainerBuilder();
        $containerBuilder->setParameter('kernel.root_dir', 'kernel/root');
        $containerBuilder->setParameter('kernel.environment', 'test');

        $mockEventDispatcher = $this
            ->createMock(EventDispatcherInterface::class);

        $containerBuilder->set('event_dispatcher', $mockEventDispatcher);

        $extension = new SentryExtension();
        $extension->load(['sentry' => $configuration], $containerBuilder);

        $containerBuilder->compile();

        return $containerBuilder;
    }
}
