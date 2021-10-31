<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Tracing\Doctrine\DBAL;

use Doctrine\DBAL\Driver as DriverInterface;
use Sentry\SentryBundle\Tests\DoctrineTestCase;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriver;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriverConnectionFactoryInterface;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriverMiddleware;
use Sentry\State\HubInterface;
use Symfony\Bridge\PhpUnit\ExpectDeprecationTrait;

final class TracingDriverMiddlewareTest extends DoctrineTestCase
{
    use ExpectDeprecationTrait;

    public static function setUpBeforeClass(): void
    {
        if (!self::isDoctrineDBALInstalled()) {
            self::markTestSkipped('This test requires the "doctrine/dbal" Composer package.');
        }
    }

    public function testConstructorThrowsExceptionIfArgumentIsInvalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The constructor requires either an instance of the "Sentry\\State\\HubInterface" interface or an instance of the "Sentry\\SentryBundle\\Tracing\\Doctrine\DBAL\\TracingDriverConnectionFactoryInterface" interface.');

        new TracingDriverMiddleware(null);
    }

    /**
     * @group legacy
     */
    public function testWrapWithProvidedHubInstance(): void
    {
        $this->expectDeprecation('Not passing an instance of the "Sentry\\SentryBundle\\Tracing\\Doctrine\DBAL\\TracingDriverConnectionFactoryInterface" interface as argument of the constructor is deprecated since version 4.2 and will not work since version 5.0.');

        $driver = $this->createMock(DriverInterface::class);
        $hub = $this->createMock(HubInterface::class);
        $middleware = new TracingDriverMiddleware($hub);

        $this->assertInstanceOf(TracingDriver::class, $middleware->wrap($driver));
    }

    public function testWrapWithProvidedConnectionFactoryInstance(): void
    {
        $driver = $this->createMock(DriverInterface::class);
        $connectionFactory = $this->createMock(TracingDriverConnectionFactoryInterface::class);
        $middleware = new TracingDriverMiddleware($connectionFactory);

        $this->assertInstanceOf(TracingDriver::class, $middleware->wrap($driver));
    }
}
