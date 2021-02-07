<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\DependencyInjection\Compiler;

use Doctrine\DBAL\Configuration;
use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\DependencyInjection\Compiler\DbalTracingDriverPass;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriverMiddleware;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class DbalTracingDriverPassTest extends TestCase
{
    public function testProcess(): void
    {
        $container = $this->createContainerBuilder();
        $container->setParameter('doctrine.connections', ['doctrine.dbal.foo_connection', 'doctrine.dbal.bar_connection', 'doctrine.dbal.baz_connection']);
        $container->setParameter('sentry.tracing.dbal.connections', ['foo', 'bar', 'baz', 'qux']);

        $container
            ->register('foo.service', \stdClass::class)
            ->setPublic(true);

        $container
            ->register('doctrine.dbal.foo_connection.configuration', Configuration::class)
            ->setPublic(true);

        $container
            ->register('doctrine.dbal.bar_connection.configuration', Configuration::class)
            ->addMethodCall('setMiddlewares', [[]])
            ->setPublic(true);

        $container
            ->register('doctrine.dbal.baz_connection.configuration', Configuration::class)
            ->addMethodCall('setMiddlewares', [[new Reference('foo.service')]])
            ->setPublic(true);

        $container->compile();

        $this->assertEquals(
            [
                [
                    'setMiddlewares',
                    [[new Reference(TracingDriverMiddleware::class)]],
                ],
            ],
            $container->getDefinition('doctrine.dbal.foo_connection.configuration')->getMethodCalls()
        );

        $this->assertEquals(
            [
                [
                    'setMiddlewares',
                    [[new Reference(TracingDriverMiddleware::class)]],
                ],
            ],
            $container->getDefinition('doctrine.dbal.bar_connection.configuration')->getMethodCalls()
        );

        $this->assertEquals(
            [
                [
                    'setMiddlewares',
                    [
                        [
                            new Reference('foo.service'),
                            new Reference(TracingDriverMiddleware::class),
                        ],
                    ],
                ],
            ],
            $container->getDefinition('doctrine.dbal.baz_connection.configuration')->getMethodCalls()
        );
    }

    public function testProcessDoesNothingIfDoctrineConnectionsParamIsMissing(): void
    {
        $container = $this->createContainerBuilder();
        $container->setParameter('sentry.tracing.dbal.connections', ['foo']);

        $container
            ->register('doctrine.dbal.foo_connection.configuration', Configuration::class)
            ->setPublic(true);

        $container->compile();

        $this->assertEmpty($container->getDefinition('doctrine.dbal.foo_connection.configuration')->getMethodCalls());
    }

    private function createContainerBuilder(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->addCompilerPass(new DbalTracingDriverPass());

        $container
            ->register(TracingDriverMiddleware::class, TracingDriverMiddleware::class)
            ->setPublic(true);

        return $container;
    }
}
