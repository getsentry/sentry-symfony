<?php

namespace Sentry\SentryBundle\Test\DependencyInjection;

use Sentry\SentryBundle\DependencyInjection\SentryExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class ExtensionTest extends \PHPUnit_Framework_TestCase
{
    const CONFIG_ROOT = 'sentry';

    public function test_that_it_uses_kernel_root_as_app_path_by_default()
    {
        $container = $this->getContainer();

        $this->assertSame(
            'kernel/root',
            $container->getParameter('sentry.app_path')
        );
    }

    public function test_that_it_uses_app_path_value()
    {
        $container = $this->getContainer(array(
            static::CONFIG_ROOT => array(
                'app_path' => 'sentry/app/path',
            ),
        ));

        $this->assertSame(
            'sentry/app/path',
            $container->getParameter('sentry.app_path')
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
        $container = $this->getContainer(array(
            static::CONFIG_ROOT => array(
                'client' => 'clientClass',
            ),
        ));

        $this->assertSame(
            'clientClass',
            $container->getParameter('sentry.client')
        );
    }

    public function test_that_it_uses_kernel_environment_as_environment_by_default()
    {
        $container = $this->getContainer();

        $this->assertSame(
            'test',
            $container->getParameter('sentry.environment')
        );
    }

    public function test_that_it_uses_environment_value()
    {
        $container = $this->getContainer(array(
            static::CONFIG_ROOT => array(
                'environment' => 'custom_env',
            ),
        ));

        $this->assertSame(
            'custom_env',
            $container->getParameter('sentry.environment')
        );
    }

    public function test_that_it_uses_null_as_dsn_default_value()
    {
        $container = $this->getContainer();

        $this->assertSame(
            null,
            $container->getParameter('sentry.dsn')
        );
    }

    public function test_that_it_uses_dsn_value()
    {
        $container = $this->getContainer(array(
            static::CONFIG_ROOT => array(
                'dsn' => 'custom_dsn',
            ),
        ));

        $this->assertSame(
            'custom_dsn',
            $container->getParameter('sentry.dsn')
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
        $container = $this->getContainer(array(
            static::CONFIG_ROOT => array(
                'exception_listener' => 'exceptionListenerClass',
            ),
        ));

        $this->assertSame(
            'exceptionListenerClass',
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
        $container = $this->getContainer(array(
            static::CONFIG_ROOT => array(
                'skip_capture' => array(
                    'classA',
                    'classB',
                ),
            ),
        ));

        $this->assertSame(
            array('classA', 'classB'),
            $container->getParameter('sentry.skip_capture')
        );
    }

    public function test_that_it_uses_null_as_release_by_default()
    {
        $container = $this->getContainer();

        $this->assertSame(
            null,
            $container->getParameter('sentry.release')
        );
    }

    public function test_that_it_uses_release_value()
    {
        $container = $this->getContainer(array(
            static::CONFIG_ROOT => array(
                'release' => '1.0',
            ),
        ));

        $this->assertSame(
            '1.0',
            $container->getParameter('sentry.release')
        );
    }

    public function test_that_it_uses_array_with_kernel_parent_as_prefix_by_default()
    {
        $container = $this->getContainer();

        $this->assertSame(
            array('kernel/root/..'),
            $container->getParameter('sentry.prefixes')
        );
    }

    public function test_that_it_uses_prefixes_value()
    {
        $container = $this->getContainer(array(
            static::CONFIG_ROOT => array(
                'prefixes' => array(
                    'dirA',
                    'dirB',
                ),
            ),
        ));

        $this->assertSame(
            array('dirA', 'dirB'),
            $container->getParameter('sentry.prefixes')
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
                array('event' => 'kernel.request', 'method' => 'onKernelRequest'),
                array('event' => 'kernel.exception', 'method' => 'onKernelException'),
                array('event' => 'console.exception', 'method' => 'onConsoleException'),
            ),
            $tags
        );
    }

    private function getContainer(array $options = array())
    {
        $containerBuilder = new ContainerBuilder();
        $containerBuilder->setParameter('kernel.root_dir', 'kernel/root');
        $containerBuilder->setParameter('kernel.environment', 'test');

        $extension = new SentryExtension();

        $extension->load($options, $containerBuilder);

        $containerBuilder->compile();

        return $containerBuilder;
    }
}
