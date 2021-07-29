<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Tracing\Doctrine\DBAL;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Connection as DriverConnectionInterface;
use Doctrine\DBAL\Driver\DriverException as DriverExceptionInterface;
use Doctrine\DBAL\Driver\ExceptionConverterDriver as ExceptionConverterDriverInterface;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Exception\DriverException as DBALDriverException;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\VersionAwarePlatformDriver as VersionAwarePlatformDriverInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Sentry\SentryBundle\Tests\DoctrineTestCase;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriver;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriverConnection;
use Sentry\State\HubInterface;

final class TracingDriverTest extends DoctrineTestCase
{
    /**
     * @var MockObject&HubInterface
     */
    private $hub;

    protected function setUp(): void
    {
        $this->hub = $this->createMock(HubInterface::class);
    }

    public static function setUpBeforeClass(): void
    {
        if (!self::isDoctrineBundlePackageInstalled()) {
            self::markTestSkipped();
        }
    }

    public function testConnect(): void
    {
        $databasePlatform = $this->createMock(AbstractPlatform::class);
        $databasePlatform->expects($this->once())
            ->method('getName')
            ->willReturn('foo');

        $decoratedDriver = $this->createMock(DriverInterface::class);
        $decoratedDriver->expects($this->once())
            ->method('connect')
            ->with(['host' => 'localhost'], 'username', 'password', ['foo' => 'bar'])
            ->willReturn($this->createMock(DriverConnectionInterface::class));

        $decoratedDriver->expects($this->once())
            ->method('getDatabasePlatform')
            ->willReturn($databasePlatform);

        $driver = new TracingDriver($this->hub, $decoratedDriver);

        $this->assertInstanceOf(TracingDriverConnection::class, $driver->connect(['host' => 'localhost'], 'username', 'password', ['foo' => 'bar']));
    }

    public function testGetDatabasePlatform(): void
    {
        $databasePlatform = $this->createMock(AbstractPlatform::class);

        $decoratedDriver = $this->createMock(DriverInterface::class);
        $decoratedDriver->expects($this->once())
            ->method('getDatabasePlatform')
            ->willReturn($databasePlatform);

        $driver = new TracingDriver($this->hub, $decoratedDriver);

        $this->assertSame($databasePlatform, $driver->getDatabasePlatform());
    }

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

        $driver = new TracingDriver($this->hub, $decoratedDriver);

        $this->assertSame($schemaManager, $driver->getSchemaManager($connection, $databasePlatform));
    }

    public function testGetExceptionConverter(): void
    {
        if (!self::isDoctrineDBALVersion3Installed()) {
            $this->markTestSkipped('This test requires the version of the "doctrine/dbal" Composer package to be >= 3.0.');
        }

        $exceptionConverter = $this->createMock(ExceptionConverter::class);

        $decoratedDriver = $this->createMock(DriverInterface::class);
        $decoratedDriver->expects($this->once())
            ->method('getExceptionConverter')
            ->willReturn($exceptionConverter);

        $driver = new TracingDriver($this->hub, $decoratedDriver);

        $this->assertSame($exceptionConverter, $driver->getExceptionConverter());
    }

    public function testGetExceptionConverterThrowsIfDoctrineDBALVersionIsLowerThan30(): void
    {
        if (self::isDoctrineDBALVersion3Installed()) {
            $this->markTestSkipped('This test requires the version of the "doctrine/dbal" Composer package to be < 3.0.');
        }

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('The Sentry\\SentryBundle\\Tracing\\Doctrine\\DBAL\\TracingDriver::getExceptionConverter() method is not supported on Doctrine DBAL 2.x.');

        $decoratedDriver = $this->createMock(DriverInterface::class);
        $driver = new TracingDriver($this->hub, $decoratedDriver);

        $driver->getExceptionConverter();
    }

    public function testGetNameIfDoctrineDBALVersionIsLowerThan30(): void
    {
        if (self::isDoctrineDBALVersion3Installed()) {
            $this->markTestSkipped('This test requires the version of the "doctrine/dbal" Composer package to be < 3.0.');
        }

        $decoratedDriver = $this->createMock(DriverInterface::class);
        $decoratedDriver->expects($this->once())
            ->method('getName')
            ->willReturn('foo');

        $driver = new TracingDriver($this->hub, $decoratedDriver);

        $this->assertSame('foo', $driver->getName());
    }

    public function testGetNameThrowsIfDoctrineDBALVersionIsAtLeast30(): void
    {
        if (!self::isDoctrineDBALVersion3Installed()) {
            $this->markTestSkipped('This test requires the version of the "doctrine/dbal" Composer package to be >= 3.0.');
        }

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('The Sentry\\SentryBundle\\Tracing\\Doctrine\\DBAL\\TracingDriver::getName() method is not supported on Doctrine DBAL 3.0.');

        $driver = new TracingDriver($this->hub, $this->createMock(DriverInterface::class));
        $driver->getName();
    }

    public function testGetDatabaseIfDoctrineDBALVersionIsLowerThan30(): void
    {
        if (self::isDoctrineDBALVersion3Installed()) {
            $this->markTestSkipped('This test requires the version of the "doctrine/dbal" Composer package to be < 3.0.');
        }

        $connection = $this->createMock(Connection::class);

        $decoratedDriver = $this->createMock(DriverInterface::class);
        $decoratedDriver->expects($this->once())
            ->method('getDatabase')
            ->with($connection)
            ->willReturn('foo');

        $driver = new TracingDriver($this->hub, $decoratedDriver);

        $this->assertSame('foo', $driver->getDatabase($connection));
    }

    public function testGetDatabaseThrowsIfDoctrineDBALVersionIsAtLeast30(): void
    {
        if (!self::isDoctrineDBALVersion3Installed()) {
            $this->markTestSkipped('This test requires the version of the "doctrine/dbal" Composer package to be >= 3.0.');
        }

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('The Sentry\\SentryBundle\\Tracing\\Doctrine\\DBAL\\TracingDriver::getDatabase() method is not supported on Doctrine DBAL 3.0.');

        $driver = new TracingDriver($this->hub, $this->createMock(DriverInterface::class));
        $driver->getDatabase($this->createMock(Connection::class));
    }

    public function testCreateDatabasePlatformForVersion(): void
    {
        $databasePlatform = $this->createMock(AbstractPlatform::class);

        $decoratedDriver = $this->createMock(StubVersionAwarePlatformDriverInterface::class);
        $decoratedDriver->expects($this->once())
            ->method('createDatabasePlatformForVersion')
            ->with('5.7')
            ->willReturn($databasePlatform);

        $driver = new TracingDriver($this->hub, $decoratedDriver);

        $this->assertSame($databasePlatform, $driver->createDatabasePlatformForVersion('5.7'));
    }

    public function testCreateDatabasePlatformForVersionWhenDriverDoesNotImplementInterface(): void
    {
        $databasePlatform = $this->createMock(AbstractPlatform::class);

        $decoratedDriver = $this->createMock(DriverInterface::class);
        $decoratedDriver->expects($this->once())
            ->method('getDatabasePlatform')
            ->willReturn($databasePlatform);

        $driver = new TracingDriver($this->hub, $decoratedDriver);

        $this->assertSame($databasePlatform, $driver->createDatabasePlatformForVersion('5.7'));
    }

    public function testConvertException(): void
    {
        if (!self::isDoctrineDBALVersion2Installed()) {
            $this->markTestSkipped('This test requires the version of the "doctrine/dbal" Composer package to be ^2.13.');
        }

        $exception = $this->createMock(DriverExceptionInterface::class);
        $convertedException = new DBALDriverException('foo', $exception);

        $decoratedDriver = $this->createMock(StubExceptionConverterDriverInterface::class);
        $decoratedDriver->expects($this->once())
            ->method('convertException')
            ->with('foo', $exception)
            ->willReturn($convertedException);

        $driver = new TracingDriver($this->hub, $decoratedDriver);

        $this->assertSame($convertedException, $driver->convertException('foo', $exception));
    }

    public function testConvertExceptionThrowsIfDoctrineDBALVersionIsAtLeast30(): void
    {
        if (!self::isDoctrineDBALVersion3Installed()) {
            $this->markTestSkipped('This test requires the version of the "doctrine/dbal" Composer package to be >= 3.0.');
        }

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('The Sentry\\SentryBundle\\Tracing\\Doctrine\\DBAL\\TracingDriver::convertException() method is not supported on Doctrine DBAL 3.0.');

        $driver = new TracingDriver($this->hub, $this->createMock(StubExceptionConverterDriverInterface::class));
        $driver->convertException('foo', $this->createMock(DriverExceptionInterface::class));
    }
}

if (interface_exists(DriverInterface::class)) {
    if (interface_exists(VersionAwarePlatformDriverInterface::class)) {
        interface StubVersionAwarePlatformDriverInterface extends DriverInterface, VersionAwarePlatformDriverInterface
        {
        }
    }

    if (interface_exists(ExceptionConverterDriverInterface::class)) {
        interface StubExceptionConverterDriverInterface extends ExceptionConverterDriverInterface, DriverInterface
        {
        }
    } else {
        interface StubExceptionConverterDriverInterface extends DriverInterface
        {
        }
    }

    if (!interface_exists(DriverExceptionInterface::class)) {
        class_alias(Exception::class, DriverExceptionInterface::class);
    }
}
