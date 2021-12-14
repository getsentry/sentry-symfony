<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Tracing\Doctrine\DBAL;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use PHPUnit\Framework\MockObject\MockObject;
use Sentry\SentryBundle\Tests\DoctrineTestCase;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriverConnection;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriverConnectionFactory;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingServerInfoAwareDriverConnection;
use Sentry\State\HubInterface;

final class TracingDriverConnectionFactoryTest extends DoctrineTestCase
{
    /**
     * @var MockObject&HubInterface
     */
    private $hub;

    /**
     * @var MockObject&AbstractPlatform
     */
    private $databasePlatform;

    /**
     * @var TracingDriverConnectionFactory
     */
    private $tracingDriverConnectionFactory;

    public static function setUpBeforeClass(): void
    {
        if (!self::isDoctrineDBALInstalled()) {
            self::markTestSkipped('This test requires the "doctrine/dbal" Composer package.');
        }
    }

    protected function setUp(): void
    {
        $this->hub = $this->createMock(HubInterface::class);
        $this->databasePlatform = $this->createMock(AbstractPlatform::class);
        $this->tracingDriverConnectionFactory = new TracingDriverConnectionFactory($this->hub);
    }

    public function testCreate(): void
    {
        $this->databasePlatform->expects($this->once())
            ->method('getName')
            ->willReturn('foo_platform');

        $connection = $this->createMock(Connection::class);
        $driverConnection = $this->tracingDriverConnectionFactory->create($connection, $this->databasePlatform, []);
        $expectedDriverConnection = new TracingDriverConnection($this->hub, $connection, 'foo_platform', []);

        $this->assertEquals($expectedDriverConnection, $driverConnection);
    }

    public function testCreateWithServerInfoAwareConnection(): void
    {
        if (!self::isDoctrineDBALVersion3Installed()) {
            self::markTestSkipped('This test requires the version of the "doctrine/dbal" Composer package to be >= 3.0.');
        }

        $this->databasePlatform->expects($this->once())
            ->method('getName')
            ->willReturn('foo_platform');

        $connection = $this->createMock(ServerInfoAwareConnectionStub::class);
        $driverConnection = $this->tracingDriverConnectionFactory->create($connection, $this->databasePlatform, []);
        $expectedDriverConnection = new TracingServerInfoAwareDriverConnection(new TracingDriverConnection($this->hub, $connection, 'foo_platform', []));

        $this->assertEquals($expectedDriverConnection, $driverConnection);
    }
}
