<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Tracing\Doctrine\DBAL;

use Doctrine\DBAL\Driver as DriverInterface;
use Sentry\SentryBundle\Tests\DoctrineTestCase;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriver;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriverConnectionFactoryInterface;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriverMiddleware;

final class TracingDriverMiddlewareTest extends DoctrineTestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!self::isDoctrineDBALInstalled()) {
            self::markTestSkipped('This test requires the "doctrine/dbal" Composer package.');
        }
    }

    public function testWrapWithProvidedConnectionFactoryInstance(): void
    {
        $driver = $this->createMock(DriverInterface::class);
        $connectionFactory = $this->createMock(TracingDriverConnectionFactoryInterface::class);
        $middleware = new TracingDriverMiddleware($connectionFactory);

        $this->assertInstanceOf(TracingDriver::class, $middleware->wrap($driver));
    }
}
