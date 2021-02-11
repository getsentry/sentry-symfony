<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\DependencyInjection\Compiler;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Sentry\SentryBundle\DependencyInjection\Compiler\DbalTracingPass;
use Sentry\SentryBundle\Tests\DoctrineTestCase;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\ConnectionConfigurator;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriverMiddleware;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class DbalTracingPassTest extends DoctrineTestCase
{
    public function testProcessWithDoctrineDBALVersionAtLeast30(): void
    {
        if (!$this->isDoctrineDBALVersion3Installed()) {
            $this->markTestSkipped('This test requires the version of the "doctrine/dbal" Composer package to be >= 3.0.');
        }

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

    public function testProcessWithDoctrineDBALVersionLowerThan30(): void
    {
        if ($this->isDoctrineDBALVersion3Installed()) {
            $this->markTestSkipped('This test requires the version of the "doctrine/dbal" Composer package to be < 3.0.');
        }

        $connection1 = (new Definition(Connection::class))->setPublic(true);
        $connection2 = (new Definition(Connection::class))->setPublic(true);

        $container = $this->createContainerBuilder();
        $container->setParameter('doctrine.connections', ['doctrine.dbal.foo_connection', 'doctrine.dbal.bar_connection']);
        $container->setParameter('sentry.tracing.dbal.connections', ['foo', 'baz']);
        $container->setDefinition('doctrine.dbal.foo_connection', $connection1);
        $container->setDefinition('doctrine.dbal.bar_connection', $connection2);
        $container->compile();

        $this->assertEquals([new Reference(ConnectionConfigurator::class), 'configure'], $connection1->getConfigurator());
        $this->assertNull($connection2->getConfigurator());
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
        $container->addCompilerPass(new DbalTracingPass());

        $container
            ->register(TracingDriverMiddleware::class, TracingDriverMiddleware::class)
            ->setPublic(true);

        $container
            ->register(ConnectionConfigurator::class, ConnectionConfigurator::class)
            ->setPublic(true);

        return $container;
    }
}
