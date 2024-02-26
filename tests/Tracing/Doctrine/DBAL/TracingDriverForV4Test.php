<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Tracing\Doctrine\DBAL;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Connection as DriverConnectionInterface;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use PHPUnit\Framework\MockObject\MockObject;
use Sentry\SentryBundle\Tests\DoctrineTestCase;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriverConnectionFactoryInterface;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriverConnectionInterface;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriverForV4;

final class TracingDriverForV4Test extends DoctrineTestCase
{
    /**
     * @var MockObject&TracingDriverConnectionFactoryInterface
     */
    private $connectionFactory;

    public static function setUpBeforeClass(): void
    {
        if (!self::isDoctrineDBALVersion4Installed()) {
            self::markTestSkipped('This test requires the version of the "doctrine/dbal" Composer package to be >= 4.0.');
        }
    }

    protected function setUp(): void
    {
        $this->connectionFactory = $this->createMock(TracingDriverConnectionFactoryInterface::class);
    }

    public function testConnect(): void
    {
        $params = ['host' => 'localhost'];
        $databasePlatform = $this->createMock(AbstractPlatform::class);
        $driverConnection = $this->createMock(DriverConnectionInterface::class);
        $tracingDriverConnection = $this->createMock(TracingDriverConnectionInterface::class);
        $decoratedDriver = $this->createMock(DriverInterface::class);

        $decoratedDriver->expects($this->once())
            ->method('connect')
            ->with($params)
            ->willReturn($driverConnection);

        $decoratedDriver->expects($this->once())
            ->method('getDatabasePlatform')
            ->willReturn($databasePlatform);

        $this->connectionFactory->expects($this->once())
            ->method('create')
            ->with($driverConnection, $databasePlatform, $params)
            ->willReturn($tracingDriverConnection);

        $driver = new TracingDriverForV4($this->connectionFactory, $decoratedDriver);

        $this->assertSame($tracingDriverConnection, $driver->connect($params));
    }
}
