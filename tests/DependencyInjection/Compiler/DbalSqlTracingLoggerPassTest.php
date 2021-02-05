<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\DependencyInjection\Compiler;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Logging\LoggerChain;
use Doctrine\DBAL\Logging\SQLLogger;
use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\DependencyInjection\Compiler\DbalSqlTracingLoggerPass;
use Sentry\SentryBundle\Tracing\DbalSqlTracingLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class DbalSqlTracingLoggerPassTest extends TestCase
{
    public function testProcess(): void
    {
        /**
         * +---------------------------------------+-----------------------------------------------------------------------------------------------------------+
         * | $setSQLLoggerMethodCall before        | $setSQLLoggerMethodCall after                                                                             |
         * +---------------------------------------+-----------------------------------------------------------------------------------------------------------+
         * | null                                  | Reference(DbalSqlTracingLogger::class)                                                                    |
         * +---------------------------------------+-----------------------------------------------------------------------------------------------------------+
         * | Reference(doctrine.dbal.logger)       | Definition(LoggerChain::class, [Reference(doctrine.dbal.logger), Reference(DbalSqlTracingLogger::class)]) |
         * +---------------------------------------+-----------------------------------------------------------------------------------------------------------+
         * | Reference(doctrine.dbal.logger.chain) | Reference(doctrine.dbal.logger.chain, [..., Reference(DbalSqlTracingLogger::class)])                      |
         * +---------------------------------------+-----------------------------------------------------------------------------------------------------------+
         * | Definition(LoggerChain::class)        | Definition(LoggerChain::class, [..., Reference(DbalSqlTracingLogger::class)])                             |
         * +---------------------------------------+-----------------------------------------------------------------------------------------------------------+
         * | Definition(SQLLogger::class)          | Definition(LoggerChain::class, [Definition(SQLLogger::class), Reference(DbalSqlTracingLogger::class)])    |
         * +---------------------------------------+-----------------------------------------------------------------------------------------------------------+.
         */
        $container = $this->createContainerBuilder();

        $container->setParameter('doctrine.connections', ['connection_1', 'connection_2', 'connection_3', 'connection_4', 'connection_5']);
        $container->setParameter('sentry.tracing.dbal.connections', ['connection_1', 'connection_2', 'connection_3', 'connection_4', 'connection_5']);

        $container
            ->register('doctrine.dbal.logger', SQLLogger::class)
            ->setPublic(true);

        $container
            ->register('doctrine.dbal.logger.chain', LoggerChain::class)
            ->setArgument(0, [new Reference('doctrine.dbal.logger')])
            ->setPublic(true);

        $container
            ->register('doctrine.dbal.connection_1_connection.configuration', Configuration::class)
            ->setPublic(true);

        $container
            ->register('doctrine.dbal.connection_2_connection.configuration', Configuration::class)
            ->addMethodCall('setSQLLogger', [new Reference('doctrine.dbal.logger')])
            ->setPublic(true);

        $container
            ->register('doctrine.dbal.connection_3_connection.configuration', Configuration::class)
            ->addMethodCall('setSQLLogger', [new Reference('doctrine.dbal.logger.chain')])
            ->setPublic(true);

        $container
            ->register('doctrine.dbal.connection_4_connection.configuration', Configuration::class)
            ->addMethodCall('setSQLLogger', [new Definition(LoggerChain::class, [[new Reference('doctrine.dbal.logger')]])])
            ->setPublic(true);

        $container
            ->register('doctrine.dbal.connection_5_connection.configuration', Configuration::class)
            ->addMethodCall('setSQLLogger', [new Definition(SQLLogger::class)])
            ->setPublic(true);

        $container->compile();

        $this->assertEquals(
            [['setSQLLogger', [new Reference(DbalSqlTracingLogger::class)]]],
            $container->getDefinition('doctrine.dbal.connection_1_connection.configuration')->getMethodCalls()
        );

        $this->assertEquals(
            [
                [
                    'setSQLLogger',
                    [
                        new Definition(LoggerChain::class, [[
                            new Reference('doctrine.dbal.logger'),
                            new Reference(DbalSqlTracingLogger::class),
                        ]]),
                    ],
                ],
            ],
            $container->getDefinition('doctrine.dbal.connection_2_connection.configuration')->getMethodCalls()
        );

        $this->assertEquals(
            [['setSQLLogger', [new Reference('doctrine.dbal.logger.chain')]]],
            $container->getDefinition('doctrine.dbal.connection_3_connection.configuration')->getMethodCalls()
        );

        $this->assertEquals(
            [
                [
                    new Reference('doctrine.dbal.logger'),
                    new Reference(DbalSqlTracingLogger::class),
                ],
            ],
            $container->getDefinition('doctrine.dbal.logger.chain')->getArguments()
        );

        $this->assertEquals(
            [
                [
                    'setSQLLogger',
                    [
                        new Definition(LoggerChain::class, [[
                            new Reference('doctrine.dbal.logger'),
                            new Reference(DbalSqlTracingLogger::class),
                        ]]),
                    ],
                ],
            ],
            $container->getDefinition('doctrine.dbal.connection_4_connection.configuration')->getMethodCalls()
        );

        $this->assertEquals(
            [
                [
                    'setSQLLogger',
                    [
                        new Definition(LoggerChain::class, [[
                            new Definition(SQLLogger::class),
                            new Reference(DbalSqlTracingLogger::class),
                        ]]),
                    ],
                ],
            ],
            $container->getDefinition('doctrine.dbal.connection_5_connection.configuration')->getMethodCalls()
        );
    }

    private function createContainerBuilder(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->addCompilerPass(new DbalSqlTracingLoggerPass());

        $container
            ->register(DbalSqlTracingLogger::class, DbalSqlTracingLogger::class)
            ->setPublic(true);

        return $container;
    }
}
