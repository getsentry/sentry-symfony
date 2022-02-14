<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Tracing\Doctrine\DBAL;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\MockObject\MockObject;
use Sentry\SentryBundle\Tests\DoctrineTestCase;
use Sentry\SentryBundle\Tests\Tracing\Doctrine\DBAL\Fixture\NativeDriverConnectionInterfaceStub;
use Sentry\SentryBundle\Tests\Tracing\Doctrine\DBAL\Fixture\ServerInfoAwareConnectionStub;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriverConnectionInterface;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingServerInfoAwareDriverConnection;

final class TracingServerInfoAwareDriverConnectionTest extends DoctrineTestCase
{
    /**
     * @var MockObject&TracingDriverConnectionInterface
     */
    private $decoratedConnection;

    /**
     * @var TracingServerInfoAwareDriverConnection
     */
    private $connection;

    public static function setUpBeforeClass(): void
    {
        if (!self::isDoctrineBundlePackageInstalled()) {
            self::markTestSkipped();
        }
    }

    protected function setUp(): void
    {
        $this->decoratedConnection = $this->createMock(TracingDriverConnectionInterface::class);
        $this->connection = new TracingServerInfoAwareDriverConnection($this->decoratedConnection);
    }

    public function testPrepare(): void
    {
        $statement = $this->createMock(Statement::class);

        $this->decoratedConnection->expects($this->once())
            ->method('prepare')
            ->with('SELECT 1 + 1')
            ->willReturn($statement);

        $this->assertSame($statement, $this->connection->prepare('SELECT 1 + 1'));
    }

    public function testQuery(): void
    {
        $result = $this->createMock(Result::class);

        $this->decoratedConnection->expects($this->once())
            ->method('query')
            ->with('SELECT 1 + 1', 'foo', 'bar')
            ->willReturn($result);

        $this->assertSame($result, $this->connection->query('SELECT 1 + 1', 'foo', 'bar'));
    }

    public function testQuote(): void
    {
        $this->decoratedConnection->expects($this->once())
            ->method('quote')
            ->with('foo', ParameterType::STRING)
            ->willReturn('foo');

        $this->assertSame('foo', $this->connection->quote('foo'));
    }

    public function testExec(): void
    {
        $this->decoratedConnection->expects($this->once())
            ->method('exec')
            ->with('SELECT 1 + 1')
            ->willReturn(10);

        $this->assertSame(10, $this->connection->exec('SELECT 1 + 1'));
    }

    public function testLastInsertId(): void
    {
        $this->decoratedConnection->expects($this->once())
            ->method('lastInsertId')
            ->with('foo')
            ->willReturn('10');

        $this->assertSame('10', $this->connection->lastInsertId('foo'));
    }

    public function testBeginTransaction(): void
    {
        $this->decoratedConnection->expects($this->once())
            ->method('beginTransaction')
            ->willReturn(false);

        $this->assertFalse($this->connection->beginTransaction());
    }

    public function testCommit(): void
    {
        $this->decoratedConnection->expects($this->once())
            ->method('commit')
            ->willReturn(false);

        $this->assertFalse($this->connection->commit());
    }

    public function testRollBack(): void
    {
        $this->decoratedConnection->expects($this->once())
            ->method('rollBack')
            ->willReturn(false);

        $this->assertFalse($this->connection->rollBack());
    }

    public function testGetServerVersion(): void
    {
        $wrappedConnection = $this->createMock(ServerInfoAwareConnectionStub::class);

        $wrappedConnection->expects($this->once())
            ->method('getServerVersion')
            ->willReturn('0.0.1');

        $this->decoratedConnection->expects($this->once())
            ->method('getWrappedConnection')
            ->willReturn($wrappedConnection);

        $this->assertSame('0.0.1', $this->connection->getServerVersion());
    }

    public function testGetServerVersionThrowsExceptionIfWrappedConnectionIsNotServerInfoAware(): void
    {
        $this->decoratedConnection->expects($this->once())
            ->method('getWrappedConnection')
            ->willReturn($this->createMock(Connection::class));

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('The wrapped connection must be an instance of the "Doctrine\\DBAL\\Driver\\ServerInfoAwareConnection" interface.');

        $this->connection->getServerVersion();
    }

    public function testRequiresQueryForServerVersion(): void
    {
        if (!self::isDoctrineDBALVersion2Installed()) {
            self::markTestSkipped('This test requires the version of the "doctrine/dbal" Composer package to be ^2.13.');
        }

        $wrappedConnection = $this->createMock(ServerInfoAwareConnectionStub::class);
        $wrappedConnection->expects($this->once())
            ->method('requiresQueryForServerVersion')
            ->willReturn(true);

        $this->decoratedConnection->expects($this->once())
            ->method('getWrappedConnection')
            ->willReturn($wrappedConnection);

        $this->assertTrue($this->connection->requiresQueryForServerVersion());
    }

    public function testRequiresQueryForServerVersionThrowsExceptionIfWrappedConnectionIsNotServerInfoAware(): void
    {
        if (!self::isDoctrineDBALVersion2Installed()) {
            self::markTestSkipped('This test requires the version of the "doctrine/dbal" Composer package to be ^2.13.');
        }

        $this->decoratedConnection->expects($this->once())
            ->method('getWrappedConnection')
            ->willReturn($this->createMock(Connection::class));

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('The wrapped connection must be an instance of the "Doctrine\\DBAL\\Driver\\ServerInfoAwareConnection" interface.');

        $this->connection->requiresQueryForServerVersion();
    }

    public function testRequiresQueryForServerVersionThrowsExceptionIfWrappedConnectionDoesNotImplementMethod(): void
    {
        if (!self::isDoctrineDBALVersion3Installed()) {
            self::markTestSkipped('This test requires the version of the "doctrine/dbal" Composer package to be >= 3.0.');
        }

        $this->decoratedConnection->expects($this->once())
            ->method('getWrappedConnection')
            ->willReturn($this->createMock(ServerInfoAwareConnectionStub::class));

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('The Sentry\\SentryBundle\\Tracing\\Doctrine\\DBAL\\TracingServerInfoAwareDriverConnection::requiresQueryForServerVersion() method is not supported on Doctrine DBAL 3.0.');

        $this->connection->requiresQueryForServerVersion();
    }

    public function testErrorCode(): void
    {
        if (!self::isDoctrineDBALVersion2Installed()) {
            self::markTestSkipped('This test requires the version of the "doctrine/dbal" Composer package to be ^2.13.');
        }

        $this->decoratedConnection->expects($this->once())
            ->method('errorCode')
            ->willReturn('1002');

        $this->assertSame('1002', $this->connection->errorCode());
    }

    public function testErrorCodeThrowsExceptionIfDecoratedConnectionDoesNotImplementMethod(): void
    {
        if (!self::isDoctrineDBALVersion3Installed()) {
            self::markTestSkipped('This test requires the version of the "doctrine/dbal" Composer package to be >= 3.0.');
        }

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('The Sentry\\SentryBundle\\Tracing\\Doctrine\\DBAL\\TracingServerInfoAwareDriverConnection::errorCode() method is not supported on Doctrine DBAL 3.0.');

        $this->connection->errorCode();
    }

    public function testErrorInfo(): void
    {
        if (!self::isDoctrineDBALVersion2Installed()) {
            self::markTestSkipped('This test requires the version of the "doctrine/dbal" Composer package to be ^2.13.');
        }

        $this->decoratedConnection->expects($this->once())
            ->method('errorInfo')
            ->willReturn(['foobar']);

        $this->assertSame(['foobar'], $this->connection->errorInfo());
    }

    public function testErrorInfoThrowsExceptionIfDecoratedConnectionDoesNotImplementMethod(): void
    {
        if (!self::isDoctrineDBALVersion3Installed()) {
            self::markTestSkipped('This test requires the version of the "doctrine/dbal" Composer package to be >= 3.0.');
        }

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('The Sentry\\SentryBundle\\Tracing\\Doctrine\\DBAL\\TracingServerInfoAwareDriverConnection::errorInfo() method is not supported on Doctrine DBAL 3.0.');

        $this->connection->errorInfo();
    }

    public function testGetWrappedConnection(): void
    {
        $wrappedConnection = $this->createMock(Connection::class);

        $this->decoratedConnection->expects($this->once())
            ->method('getWrappedConnection')
            ->willReturn($wrappedConnection);

        $this->assertSame($wrappedConnection, $this->connection->getWrappedConnection());
    }

    public function testGetNativeConnection(): void
    {
        $nativeConnection = new class() {
        };

        $decoratedConnection = $this->createMock(NativeDriverConnectionInterfaceStub::class);
        $decoratedConnection->expects($this->once())
            ->method('getNativeConnection')
            ->willReturn($nativeConnection);

        $connection = new TracingServerInfoAwareDriverConnection($decoratedConnection);

        $this->assertSame($nativeConnection, $connection->getNativeConnection());
    }

    public function testGetNativeConnectionThrowsExceptionIfDecoratedConnectionDoesNotImplementMethod(): void
    {
        $decoratedConnection = $this->createMock(TracingDriverConnectionInterface::class);
        $connection = new TracingServerInfoAwareDriverConnection($decoratedConnection);

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessageMatches('/The connection ".*?" does not support accessing the native connection\./');

        $connection->getNativeConnection();
    }
}
