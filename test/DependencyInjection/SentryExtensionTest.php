<?php

namespace Sentry\SentryBundle\Test\DependencyInjection;

use Sentry\SentryBundle\DependencyInjection\SentryExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class SentryExtensionTest extends \PHPUnit_Framework_TestCase
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

    public function test_that_it_uses_deprecated_app_path_value()
    {
        $container = $this->getContainer(
            array(
                static::CONFIG_ROOT => array(
                    'app_path' => 'sentry/app/path',
                ),
            )
        );

        $this->assertSame(
            'sentry/app/path',
            $container->getParameter('sentry.app_path')
        );
    }

    public function test_that_it_uses_app_path_value()
    {
        $container = $this->getContainer(
            array(
                static::CONFIG_ROOT => array(
                    'options' => array('app_path' => 'sentry/app/path'),
                ),
            )
        );

        $options = $container->getParameter('sentry.options');
        $this->assertSame(
            'sentry/app/path',
            $options['app_path']
        );
    }

    public function test_that_it_uses_both_new_and_deprecated_values()
    {
        $container = $this->getContainer(
            array(
                static::CONFIG_ROOT => array(
                    'app_path' => 'sentry/app/path',
                    'options' => array('app_path' => 'sentry/app/path'),
                ),
            )
        );

        $options = $container->getParameter('sentry.options');
        $this->assertSame(
            'sentry/app/path',
            $options['app_path']
        );
    }

    public function test_that_throws_exception_if_new_and_deprecated_values_dont_match()
    {
        $this->setExpectedException('Symfony\Component\Config\Definition\Exception\InvalidConfigurationException');

        $this->getContainer(
            array(
                'app_path' => 'sentry/app/path',
                static::CONFIG_ROOT => array(
                    'options' => array('app_path' => 'sentry/different/app/path'),
                ),
            )
        );
    }

    public function test_vendor_in_deprecated_default_excluded_paths()
    {
        $container = $this->getContainer();

        $this->assertContains(
            'kernel/root/../vendor',
            $container->getParameter('sentry.excluded_app_paths')
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
            'Sentry\SentryBundle\SentrySymfonyClient',
            $container->getParameter('sentry.client')
        );
    }

    public function test_that_it_uses_client_value()
    {
        $container = $this->getContainer(
            array(
                static::CONFIG_ROOT => array(
                    'client' => 'clientClass',
                ),
            )
        );

        $this->assertSame(
            'clientClass',
            $container->getParameter('sentry.client')
        );
    }

    public function test_that_it_uses_kernel_environment_as_environment_by_default_for_deprecated_config()
    {
        $container = $this->getContainer();

        $this->assertSame(
            'test',
            $container->getParameter('sentry.environment')
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

    public function test_that_it_uses_environment_value_for_deprecated_config()
    {
        $container = $this->getContainer(
            array(
                static::CONFIG_ROOT => array(
                    'environment' => 'custom_env',
                ),
            )
        );

        $this->assertSame(
            'custom_env',
            $container->getParameter('sentry.environment')
        );
    }

    public function test_that_it_uses_environment_value()
    {
        $container = $this->getContainer(
            array(
                static::CONFIG_ROOT => array(
                    'options' => array('environment' => 'custom_env'),
                ),
            )
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
            array(
                static::CONFIG_ROOT => array(
                    'dsn' => $emptyDsn,
                ),
            )
        );

        $this->assertNull($container->getParameter('sentry.dsn'));
    }

    public function emptyDsnValueProvider()
    {
        return array(
            array(null),
            array(''),
            array(' '),
            array('    '),
        );
    }

    public function test_that_it_uses_dsn_value()
    {
        $container = $this->getContainer(
            array(
                static::CONFIG_ROOT => array(
                    'dsn' => 'custom_dsn',
                ),
            )
        );

        $this->assertSame(
            'custom_dsn',
            $container->getParameter('sentry.dsn')
        );
    }

    public function test_that_it_uses_options_value()
    {
        $container = $this->getContainer(
            array(
                static::CONFIG_ROOT => array(
                    'options' => array(
                        'http_proxy' => 'http://user:password@host:port',
                    ),
                ),
            )
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

    /**
     * @expectedException Symfony\Component\Config\Definition\Exception\InvalidConfigurationException
     */
    public function test_that_it_is_invalid_if_exception_listener_fails_to_implement_required_interface()
    {
        $class = 'Sentry\SentryBundle\Test\Fixtures\InvalidExceptionListener';
        $this->getContainer(
            array(
                static::CONFIG_ROOT => array(
                    'exception_listener' => $class,
                ),
            )
        );
    }

    public function test_that_it_uses_defined_class_as_exception_listener_class_by_default()
    {
        $container = $this->getContainer();

        $this->assertSame(
            'Sentry\SentryBundle\EventListener\ExceptionListener',
            $container->getParameter('sentry.exception_listener')
        );
    }

    public function test_that_it_uses_exception_listener_value()
    {
        $class = 'Sentry\SentryBundle\Test\Fixtures\CustomExceptionListener';
        $container = $this->getContainer(
            array(
                static::CONFIG_ROOT => array(
                    'exception_listener' => $class,
                ),
            )
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
            array(
                'Symfony\Component\HttpKernel\Exception\HttpExceptionInterface',
            ),
            $container->getParameter('sentry.skip_capture')
        );
    }

    public function test_that_it_uses_skipped_capture_value()
    {
        $container = $this->getContainer(
            array(
                static::CONFIG_ROOT => array(
                    'skip_capture' => array(
                        'classA',
                        'classB',
                    ),
                ),
            )
        );

        $this->assertSame(
            array('classA', 'classB'),
            $container->getParameter('sentry.skip_capture')
        );
    }

    public function test_that_it_uses_null_as_release_by_default_for_deprecated_config()
    {
        $container = $this->getContainer();

        $this->assertNull(
            $container->getParameter('sentry.release')
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

    public function test_that_it_uses_release_value_for_deprecated_config()
    {
        $container = $this->getContainer(
            array(
                static::CONFIG_ROOT => array(
                    'release' => '1.0',
                ),
            )
        );

        $this->assertSame(
            '1.0',
            $container->getParameter('sentry.release')
        );
    }

    public function test_that_it_uses_release_value()
    {
        $container = $this->getContainer(
            array(
                static::CONFIG_ROOT => array(
                    'options' => array('release' => '1.0'),
                ),
            )
        );

        $options = $container->getParameter('sentry.options');
        $this->assertSame(
            '1.0',
            $options['release']
        );
    }

    public function test_that_it_uses_array_with_kernel_parent_as_prefix_by_default_for_deprecated_config()
    {
        $container = $this->getContainer();

        $this->assertSame(
            array('kernel/root/..'),
            $container->getParameter('sentry.prefixes')
        );
    }

    public function test_that_it_uses_array_with_kernel_parent_as_prefix_by_default()
    {
        $container = $this->getContainer();

        $options = $container->getParameter('sentry.options');
        $this->assertSame(
            array('kernel/root/..'),
            $options['prefixes']
        );
    }

    public function test_that_it_uses_prefixes_value_for_deprecated_config()
    {
        $container = $this->getContainer(
            array(
                static::CONFIG_ROOT => array(
                    'prefixes' => array(
                        'dirA',
                        'dirB',
                    ),
                ),
            )
        );

        $this->assertSame(
            array('dirA', 'dirB'),
            $container->getParameter('sentry.prefixes')
        );
    }

    public function test_that_it_uses_prefixes_value()
    {
        $container = $this->getContainer(
            array(
                static::CONFIG_ROOT => array(
                    'options' => array(
                        'prefixes' => array(
                            'dirA',
                            'dirB',
                        ),
                    ),
                ),
            )
        );

        $options = $container->getParameter('sentry.options');
        $this->assertSame(
            array('dirA', 'dirB'),
            $options['prefixes']
        );
    }

    public function test_that_it_has_sentry_client_service_and_it_defaults_to_symfony_client()
    {
        $client = $this->getContainer()->get('sentry.client');
        $this->assertInstanceOf('Sentry\SentryBundle\SentrySymfonyClient', $client);
    }

    public function test_that_it_has_sentry_exception_listener_and_it_defaults_to_default_exception_listener()
    {
        $client = $this->getContainer()->get('sentry.exception_listener');
        $this->assertInstanceOf('Sentry\SentryBundle\EventListener\ExceptionListener', $client);
    }

    public function test_that_it_has_proper_event_listener_tags_for_exception_listener()
    {
        $containerBuilder = new ContainerBuilder();
        $extension = new SentryExtension();
        $extension->load(array(), $containerBuilder);

        $definition = $containerBuilder->getDefinition('sentry.exception_listener');
        $tags = $definition->getTag('kernel.event_listener');

        $this->assertSame(
            array(
                array(
                    'event' => 'kernel.request',
                    'method' => 'onKernelRequest',
                    'priority' => '%sentry.listener_priorities.request%',
                ),
                array(
                    'event' => 'kernel.exception',
                    'method' => 'onKernelException',
                    'priority' => '%sentry.listener_priorities.kernel_exception%',
                ),
                array('event' => 'console.command', 'method' => 'onConsoleCommand'),
                array(
                    'event' => 'console.exception',
                    'method' => 'onConsoleException',
                    'priority' => '%sentry.listener_priorities.console_exception%',
                ),
            ),
            $tags
        );
    }

    /**
     * @expectedException Symfony\Component\Config\Definition\Exception\InvalidConfigurationException
     */
    public function test_that_it_throws_an_exception_on_a_mismatch_between_deprecated_and_new_configuration_options()
    {
        $this->getContainer(
            array(
                static::CONFIG_ROOT => array(
                    'environment' => '123test',
                    'options' => array(
                        'environment' => 'test123',
                    ),
                ),
            )
        );
    }

    public function test_that_it_sets_all_options()
    {
        $config = array(
            'options' => array(
                'logger' => 'logger',
                'server' => 'server',
                'secret_key' => 'secret_key',
                'public_key' => 'public_key',
                'project' => 'project',
                'auto_log_stacks' => true,
                'name' => 'name',
                'site' => 'site',
                'tags' => array(
                    'tag1' => 'tagname',
                    'tag2' => 'tagename 2',
                ),
                'release' => 'release',
                'environment' => 'environment',
                'sample_rate' => 0.9,
                'trace' => false,
                'timeout' => 1,
                'message_limit' => 512,
                'exclude' => array(
                    'test1',
                    'test2',
                ),
                'http_proxy' => 'http_proxy',
                'extra' => array(
                    'extra1' => 'extra1',
                    'extra2' => 'extra2',
                ),
                'curl_method' => 'curl_method',
                'curl_path' => 'curl_path',
                'curl_ipv4' => false,
                'ca_cert' => 'ca_cert',
                'verify_ssl' => false,
                'curl_ssl_version' => 'curl_ssl_version',
                'trust_x_forwarded_proto' => true,
                'mb_detect_order' => 'mb_detect_order',
                'error_types' => array('error_types1' => 'error_types1'),
                'app_path' => 'app_path',
                'excluded_app_paths' => array('excluded_app_path1', 'excluded_app_path2'),
                'prefixes' => array('prefix1', 'prefix2'),
                'install_default_breadcrumb_handlers' => false,
                'install_shutdown_handler' => false,
                'processors' => array('processor1', 'processor2'),
                'processorOptions' => array(
                    'processorOption1' => 'asasdf',
                ),
            ),
        );

        $container = $this->getContainer(array(static::CONFIG_ROOT => $config));

        $options = $container->getParameter('sentry.options');
        $this->assertSame(
            $config['options'],
            $options
        );
    }

    private function getContainer(array $options = array())
    {
        $containerBuilder = new ContainerBuilder();
        $containerBuilder->setParameter('kernel.root_dir', 'kernel/root');
        $containerBuilder->setParameter('kernel.environment', 'test');

        $mockEventDispatcher = $this
            ->getMock('Symfony\Component\EventDispatcher\EventDispatcherInterface');

        $containerBuilder->set('event_dispatcher', $mockEventDispatcher);

        $extension = new SentryExtension();

        $extension->load($options, $containerBuilder);

        $containerBuilder->compile();

        return $containerBuilder;
    }
}
