<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Tracing\Doctrine\DBAL;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Connection as DriverConnectionInterface;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use PHPUnit\Framework\MockObject\MockObject;
use Sentry\SentryBundle\Tests\DoctrineTestCase;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriverConnectionFactoryInterface;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriverConnectionInterface;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriverForV32;

final class TracingDriverForV32Test extends DoctrineTestCase
{
    /**
     * @var MockObject&TracingDriverConnectionFactoryInterface
     */
    private $connectionFactory;

    public static function setUpBeforeClass(): void
    {
        if (!self::isDoctrineDBALVersion32Installed()) {
            self::markTestSkipped('This test requires the version of the "doctrine/dbal" Composer package to be >= 3.2.');
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

        $driver = new TracingDriverForV32($this->connectionFactory, $decoratedDriver);

        $this->assertSame($tracingDriverConnection, $driver->connect($params));
    }

    public function testGetDatabasePlatform(): void
    {
        $databasePlatform = $this->createMock(AbstractPlatform::class);

        $decoratedDriver = $this->createMock(DriverInterface::class);
        $decoratedDriver->expects($this->once())
            ->method('getDatabasePlatform')
            ->willReturn($databasePlatform);

        $driver = new TracingDriverForV32($this->connectionFactory, $decoratedDriver);

        $this->assertSame($databasePlatform, $driver->getDatabasePlatform());
    }

    /**
     * @group legacy
     */
    public function testGetSchemaManager(): void
    {
        $connection = $this->createMock(Connection::class);
        $databasePlatform = $this->createMock(AbstractPlatform::class);
        $schemaManager = $this->createMock(AbstractSchemaManager::class);

        $decoratedDriver = $this->createMock(DriverInterface::class);
        $decoratedDriver->expects($this->once())
            ->method('getSchemaManager')
            ->with($connection, $databasePlatform)
            ->willReturn($schemaManager);

        $driver = new TracingDriverForV32($this->connectionFactory, $decoratedDriver);

        $this->assertSame($schemaManager, $driver->getSchemaManager($connection, $databasePlatform));
    }

    public function testGetExceptionConverter(): void
    {
        $exceptionConverter = $this->createMock(ExceptionConverter::class);

        $decoratedDriver = $this->createMock(DriverInterface::class);
        $decoratedDriver->expects($this->once())
            ->method('getExceptionConverter')
            ->willReturn($exceptionConverter);

        $driver = new TracingDriverForV32($this->connectionFactory, $decoratedDriver);

        $this->assertSame($exceptionConverter, $driver->getExceptionConverter());
    }

    public function testCreateDatabasePlatform(): void
    {
        $databasePlatform = $this->createMock(AbstractPlatform::class);

        $decoratedDriver = $this->createMock(DriverInterface::class);
        $decoratedDriver->expects($this->once())
            ->method('getDatabasePlatform')
            ->willReturn($databasePlatform);

        $driver = new TracingDriverForV32($this->connectionFactory, $decoratedDriver);

        $this->assertSame($databasePlatform, $driver->createDatabasePlatformForVersion('5.7'));
    }

    public function testCreateDatabasePlatformForVersionWhenDriverDefinedCreateDatabasePlatformForVersion(): void
    {
        $databasePlatform = $this->createMock(AbstractPlatform::class);

        $decoratedDriver = $this->createMock(StubCreateDatabasePlatformForVersionDriver::class);
        $decoratedDriver->expects($this->once())
            ->method('createDatabasePlatformForVersion')
            ->with('5.7')
            ->willReturn($databasePlatform);

        $driver = new TracingDriverForV32($this->connectionFactory, $decoratedDriver);

        $this->assertSame($databasePlatform, $driver->createDatabasePlatformForVersion('5.7'));
    }
}

if (interface_exists(DriverInterface::class)) {
    interface StubCreateDatabasePlatformForVersionDriver extends DriverInterface
    {
        public function createDatabasePlatformForVersion(string $version): AbstractPlatform;
    }
}
