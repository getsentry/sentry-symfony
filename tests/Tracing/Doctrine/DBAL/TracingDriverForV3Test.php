<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Tracing\Doctrine\DBAL;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Connection as DriverConnectionInterface;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\VersionAwarePlatformDriver as VersionAwarePlatformDriverInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Sentry\SentryBundle\Tests\DoctrineTestCase;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriverConnection;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriverForV3;
use Sentry\State\HubInterface;

final class TracingDriverForV3Test extends DoctrineTestCase
{
    /**
     * @var MockObject&HubInterface
     */
    private $hub;

    public static function setUpBeforeClass(): void
    {
        if (!self::isDoctrineDBALVersion3Installed()) {
            self::markTestSkipped('This test requires the version of the "doctrine/dbal" Composer package to be >= 3.0.');
        }
    }

    protected function setUp(): void
    {
        $this->hub = $this->createMock(HubInterface::class);
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
            ->with(['host' => 'localhost'])
            ->willReturn($this->createMock(DriverConnectionInterface::class));

        $decoratedDriver->expects($this->once())
            ->method('getDatabasePlatform')
            ->willReturn($databasePlatform);

        $driver = new TracingDriverForV3($this->hub, $decoratedDriver);

        $this->assertInstanceOf(TracingDriverConnection::class, $driver->connect(['host' => 'localhost']));
    }

    public function testGetDatabasePlatform(): void
    {
        $databasePlatform = $this->createMock(AbstractPlatform::class);

        $decoratedDriver = $this->createMock(DriverInterface::class);
        $decoratedDriver->expects($this->once())
            ->method('getDatabasePlatform')
            ->willReturn($databasePlatform);

        $driver = new TracingDriverForV3($this->hub, $decoratedDriver);

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

        $driver = new TracingDriverForV3($this->hub, $decoratedDriver);

        $this->assertSame($schemaManager, $driver->getSchemaManager($connection, $databasePlatform));
    }

    public function testGetExceptionConverter(): void
    {
        $exceptionConverter = $this->createMock(ExceptionConverter::class);

        $decoratedDriver = $this->createMock(DriverInterface::class);
        $decoratedDriver->expects($this->once())
            ->method('getExceptionConverter')
            ->willReturn($exceptionConverter);

        $driver = new TracingDriverForV3($this->hub, $decoratedDriver);

        $this->assertSame($exceptionConverter, $driver->getExceptionConverter());
    }

    public function testCreateDatabasePlatformForVersion(): void
    {
        $databasePlatform = $this->createMock(AbstractPlatform::class);

        $decoratedDriver = $this->createMock(VersionAwarePlatformDriverInterface::class);
        $decoratedDriver->expects($this->once())
            ->method('createDatabasePlatformForVersion')
            ->with('5.7')
            ->willReturn($databasePlatform);

        $driver = new TracingDriverForV3($this->hub, $decoratedDriver);

        $this->assertSame($databasePlatform, $driver->createDatabasePlatformForVersion('5.7'));
    }

    public function testCreateDatabasePlatformForVersionWhenDriverDoesNotImplementInterface(): void
    {
        $databasePlatform = $this->createMock(AbstractPlatform::class);

        $decoratedDriver = $this->createMock(DriverInterface::class);
        $decoratedDriver->expects($this->once())
            ->method('getDatabasePlatform')
            ->willReturn($databasePlatform);

        $driver = new TracingDriverForV3($this->hub, $decoratedDriver);

        $this->assertSame($databasePlatform, $driver->createDatabasePlatformForVersion('5.7'));
    }
}
