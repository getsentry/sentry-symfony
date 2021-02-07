<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Tracing\Doctrine\DBAL;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\API\ExceptionConverter as ExceptionConverterInterface;
use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Connection as DriverConnectionInterface;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriver;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriverConnection;
use Sentry\State\HubInterface;

final class TracingDriverTest extends TestCase
{
    /**
     * @var MockObject&HubInterface
     */
    private $hub;

    /**
     * @var DriverInterface&MockObject
     */
    private $decoratedDriver;

    /**
     * @var TracingDriver
     */
    private $driver;

    protected function setUp(): void
    {
        $this->hub = $this->createMock(HubInterface::class);
        $this->decoratedDriver = $this->createMock(DriverInterface::class);
        $this->driver = new TracingDriver($this->hub, $this->decoratedDriver);
    }

    public function testConnect(): void
    {
        $params = ['foo' => 'bar'];

        $databasePlatform = $this->createMock(AbstractPlatform::class);
        $databasePlatform->expects($this->once())
            ->method('getName')
            ->willReturn('foo');

        $this->decoratedDriver->expects($this->once())
            ->method('connect')
            ->with($params)
            ->willReturn($this->createMock(DriverConnectionInterface::class));

        $this->decoratedDriver->expects($this->once())
            ->method('getDatabasePlatform')
            ->willReturn($databasePlatform);

        $this->assertInstanceOf(TracingDriverConnection::class, $this->driver->connect($params));
    }

    public function testGetDatabasePlatform(): void
    {
        $databasePlatform = $this->createMock(AbstractPlatform::class);

        $this->decoratedDriver->expects($this->once())
            ->method('getDatabasePlatform')
            ->willReturn($databasePlatform);

        $this->assertSame($databasePlatform, $this->driver->getDatabasePlatform());
    }

    public function testGetSchemaManager(): void
    {
        $connection = $this->createMock(Connection::class);
        $databasePlatform = $this->createMock(AbstractPlatform::class);
        $schemaManager = $this->createMock(AbstractSchemaManager::class);

        $this->decoratedDriver->expects($this->once())
            ->method('getSchemaManager')
            ->with($connection, $databasePlatform)
            ->willReturn($schemaManager);

        $this->assertSame($schemaManager, $this->driver->getSchemaManager($connection, $databasePlatform));
    }

    public function testGetExceptionConverter(): void
    {
        $exceptionConverter = $this->createMock(ExceptionConverterInterface::class);

        $this->decoratedDriver->expects($this->once())
            ->method('getExceptionConverter')
            ->willReturn($exceptionConverter);

        $this->assertSame($exceptionConverter, $this->driver->getExceptionConverter());
    }
}
