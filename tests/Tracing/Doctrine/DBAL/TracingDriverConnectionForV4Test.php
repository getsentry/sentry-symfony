<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Tracing\Doctrine\DBAL;

use Doctrine\DBAL\Driver\Connection as DriverConnectionInterface;
use Doctrine\DBAL\Driver\Result as DriverResultInterface;
use Doctrine\DBAL\Driver\Statement as DriverStatementInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Sentry\SentryBundle\Tests\DoctrineTestCase;
use Sentry\SentryBundle\Tests\Tracing\Doctrine\DBAL\Fixture\NativeDriverConnectionInterfaceStub;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriverConnectionForV4 as TracingDriverConnection;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingStatement;
use Sentry\State\HubInterface;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;

/**
 * @phpstan-import-type Params from \Doctrine\DBAL\DriverManager as ConnectionParams
 */
final class TracingDriverConnectionForV4Test extends DoctrineTestCase
{
    /**
     * @var MockObject&HubInterface
     */
    private $hub;

    /**
     * @var MockObject&DriverConnectionInterface
     */
    private $decoratedConnection;

    /**
     * @var TracingDriverConnection
     */
    private $connection;

    public static function setUpBeforeClass(): void
    {
        if (!self::isDoctrineDBALVersion4Installed()) {
            self::markTestSkipped();
        }
    }

    protected function setUp(): void
    {
        $this->hub = $this->createMock(HubInterface::class);
        $this->decoratedConnection = $this->createMock(DriverConnectionInterface::class);
        $this->connection = new TracingDriverConnection($this->hub, $this->decoratedConnection, 'foo_platform', []);
    }

    /**
     * @dataProvider spanDataDataProvider
     *
     * @param array<string, mixed>  $params
     * @param array<string, string> $expectedData
     *
     * @phpstan-param ConnectionParams $params
     */
    public function testPrepare(array $params, array $expectedData): void
    {
        $sql = 'SELECT 1 + 1';
        $statement = $this->createMock(DriverStatementInterface::class);
        $resultStatement = new TracingStatement($this->hub, $statement, $sql, $expectedData);
        $connection = new TracingDriverConnection($this->hub, $this->decoratedConnection, 'foo_platform', $params);

        $transaction = new Transaction(new TransactionContext(), $this->hub);
        $transaction->initSpanRecorder();

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($transaction);

        $this->decoratedConnection->expects($this->once())
            ->method('prepare')
            ->with($sql)
            ->willReturn($statement);

        $this->assertEquals($resultStatement, $connection->prepare($sql));
        $this->assertNotNull($transaction->getSpanRecorder());

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertSame(TracingDriverConnection::SPAN_OP_CONN_PREPARE, $spans[1]->getOp());
        $this->assertSame($sql, $spans[1]->getDescription());
        $this->assertSame($expectedData, $spans[1]->getData());
        $this->assertNotNull($spans[1]->getEndTimestamp());
    }

    public function testPrepareDoesNothingIfNoSpanIsSetOnHub(): void
    {
        $sql = 'SELECT 1 + 1';
        $statement = $this->createMock(DriverStatementInterface::class);
        $resultStatement = new TracingStatement($this->hub, $statement, $sql, ['db.system' => 'foo_platform']);

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn(null);

        $this->decoratedConnection->expects($this->once())
            ->method('prepare')
            ->with($sql)
            ->willReturn($statement);

        $this->assertEquals($resultStatement, $this->connection->prepare($sql));
    }

    /**
     * @dataProvider spanDataDataProvider
     *
     * @param array<string, mixed>  $params
     * @param array<string, string> $expectedData
     *
     * @phpstan-param ConnectionParams $params
     */
    public function testQuery(array $params, array $expectedData): void
    {
        $result = $this->createMock(DriverResultInterface::class);
        $connection = new TracingDriverConnection($this->hub, $this->decoratedConnection, 'foo_platform', $params);
        $sql = 'SELECT 1 + 1';

        $transaction = new Transaction(new TransactionContext(), $this->hub);
        $transaction->initSpanRecorder();

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($transaction);

        $this->decoratedConnection->expects($this->once())
            ->method('query')
            ->with($sql)
            ->willReturn($result);

        $this->assertSame($result, $connection->query($sql));
        $this->assertNotNull($transaction->getSpanRecorder());

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertSame(TracingDriverConnection::SPAN_OP_CONN_QUERY, $spans[1]->getOp());
        $this->assertSame($sql, $spans[1]->getDescription());
        $this->assertSame($expectedData, $spans[1]->getData());
        $this->assertNotNull($spans[1]->getEndTimestamp());
    }

    public function testQueryDoesNothingIfNoSpanIsSetOnHub(): void
    {
        $result = $this->createMock(DriverResultInterface::class);
        $sql = 'SELECT 1 + 1';

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn(null);

        $this->decoratedConnection->expects($this->once())
            ->method('query')
            ->with($sql)
            ->willReturn($result);

        $this->assertSame($result, $this->connection->query($sql));
    }

    public function testQuote(): void
    {
        $this->decoratedConnection->expects($this->once())
            ->method('quote')
            ->with('foo')
            ->willReturn('foo');

        $this->assertSame('foo', $this->connection->quote('foo'));
    }

    /**
     * @dataProvider spanDataDataProvider
     *
     * @param array<string, mixed>  $params
     * @param array<string, string> $expectedData
     *
     * @phpstan-param ConnectionParams $params
     */
    public function testExec(array $params, array $expectedData): void
    {
        $connection = new TracingDriverConnection($this->hub, $this->decoratedConnection, 'foo_platform', $params);
        $sql = 'SELECT 1 + 1';

        $transaction = new Transaction(new TransactionContext(), $this->hub);
        $transaction->initSpanRecorder();

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($transaction);

        $this->decoratedConnection->expects($this->once())
            ->method('exec')
            ->with($sql)
            ->willReturn(10);

        $this->assertSame(10, $connection->exec($sql));
        $this->assertNotNull($transaction->getSpanRecorder());

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertSame(TracingDriverConnection::SPAN_OP_CONN_EXEC, $spans[1]->getOp());
        $this->assertSame($sql, $spans[1]->getDescription());
        $this->assertSame($expectedData, $spans[1]->getData());
        $this->assertNotNull($spans[1]->getEndTimestamp());
    }

    public function testLastInsertId(): void
    {
        $this->decoratedConnection->expects($this->once())
            ->method('lastInsertId')
            ->willReturn('10');

        $this->assertSame('10', $this->connection->lastInsertId());
    }

    /**
     * @dataProvider spanDataDataProvider
     *
     * @param array<string, mixed>  $params
     * @param array<string, string> $expectedData
     *
     * @phpstan-param ConnectionParams $params
     */
    public function testBeginTransaction(array $params, array $expectedData): void
    {
        $connection = new TracingDriverConnection($this->hub, $this->decoratedConnection, 'foo_platform', $params);
        $transaction = new Transaction(new TransactionContext(), $this->hub);
        $transaction->initSpanRecorder();

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($transaction);

        $this->decoratedConnection->expects($this->once())
            ->method('beginTransaction');

        $connection->beginTransaction();
        $this->assertNotNull($transaction->getSpanRecorder());

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertSame(TracingDriverConnection::SPAN_OP_CONN_BEGIN_TRANSACTION, $spans[1]->getOp());
        $this->assertSame('BEGIN TRANSACTION', $spans[1]->getDescription());
        $this->assertSame($expectedData, $spans[1]->getData());
        $this->assertNotNull($spans[1]->getEndTimestamp());
    }

    public function testBeginTransactionDoesNothingIfNoSpanIsSetOnHub(): void
    {
        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn(null);

        $this->decoratedConnection->expects($this->once())
            ->method('beginTransaction');

        $this->connection->beginTransaction();
    }

    /**
     * @dataProvider spanDataDataProvider
     *
     * @param array<string, mixed>  $params
     * @param array<string, string> $expectedData
     *
     * @phpstan-param ConnectionParams $params
     */
    public function testCommit(array $params, array $expectedData): void
    {
        $connection = new TracingDriverConnection($this->hub, $this->decoratedConnection, 'foo_platform', $params);
        $transaction = new Transaction(new TransactionContext(), $this->hub);
        $transaction->initSpanRecorder();

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($transaction);

        $this->decoratedConnection->expects($this->once())
            ->method('commit');

        $connection->commit();
        $this->assertNotNull($transaction->getSpanRecorder());

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertSame(TracingDriverConnection::SPAN_OP_TRANSACTION_COMMIT, $spans[1]->getOp());
        $this->assertSame('COMMIT', $spans[1]->getDescription());
        $this->assertSame($expectedData, $spans[1]->getData());
        $this->assertNotNull($spans[1]->getEndTimestamp());
    }

    public function testCommitDoesNothingIfNoSpanIsSetOnHub(): void
    {
        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn(null);

        $this->decoratedConnection->expects($this->once())
            ->method('commit');

        $this->connection->commit();
    }

    /**
     * @dataProvider spanDataDataProvider
     *
     * @param array<string, mixed>  $params
     * @param array<string, string> $expectedData
     *
     * @phpstan-param ConnectionParams $params
     */
    public function testRollBack(array $params, array $expectedData): void
    {
        $connection = new TracingDriverConnection($this->hub, $this->decoratedConnection, 'foo_platform', $params);
        $transaction = new Transaction(new TransactionContext(), $this->hub);
        $transaction->initSpanRecorder();

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($transaction);

        $this->decoratedConnection->expects($this->once())
            ->method('rollBack');

        $connection->rollBack();
        $this->assertNotNull($transaction->getSpanRecorder());

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertSame(TracingDriverConnection::SPAN_OP_TRANSACTION_ROLLBACK, $spans[1]->getOp());
        $this->assertSame('ROLLBACK', $spans[1]->getDescription());
        $this->assertSame($expectedData, $spans[1]->getData());
        $this->assertNotNull($spans[1]->getEndTimestamp());
    }

    public function testRollBackDoesNothingIfNoSpanIsSetOnHub(): void
    {
        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn(null);

        $this->decoratedConnection->expects($this->once())
            ->method('rollBack');

        $this->connection->rollBack();
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
            self::markTestSkipped('This test requires the version of the "doctrine/dbal" Composer package to be >= 3.3.');
        }

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('The Sentry\\SentryBundle\\Tracing\\Doctrine\\DBAL\\TracingDriverConnection::errorCode() method is not supported on Doctrine DBAL 3.0.');

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
            self::markTestSkipped('This test requires the version of the "doctrine/dbal" Composer package to be >= 3.3.');
        }

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('The Sentry\\SentryBundle\\Tracing\\Doctrine\\DBAL\\TracingDriverConnection::errorInfo() method is not supported on Doctrine DBAL 3.0.');

        $this->connection->errorInfo();
    }

    public function testGetWrappedConnection(): void
    {
        $connection = new TracingDriverConnection($this->hub, $this->decoratedConnection, 'foo_platform', []);

        $this->assertSame($this->decoratedConnection, $connection->getWrappedConnection());
    }

    public function testGetNativeConnection(): void
    {
        $nativeConnection = new class {
        };

        $decoratedConnection = $this->createMock(NativeDriverConnectionInterfaceStub::class);
        $decoratedConnection->expects($this->once())
            ->method('getNativeConnection')
            ->willReturn($nativeConnection);

        $connection = new TracingDriverConnection($this->hub, $decoratedConnection, 'foo_platform', []);

        $this->assertSame($nativeConnection, $connection->getNativeConnection());
    }

    /**
     * @return \Generator<mixed>
     */
    public function spanDataDataProvider(): \Generator
    {
        yield [
            [],
            ['db.system' => 'foo_platform'],
        ];

        yield [
            [
                'user' => 'root',
                'dbname' => 'INFORMATION_SCHEMA',
                'port' => 3306,
                'unix_socket' => '/var/run/mysqld/mysqld.sock',
            ],
            [
                'db.system' => 'foo_platform',
                'db.user' => 'root',
                'db.name' => 'INFORMATION_SCHEMA',
                'server.port' => '3306',
                'server.socket.address' => 'Unix',
            ],
        ];

        yield [
            [
                'user' => 'root',
                'dbname' => 'INFORMATION_SCHEMA',
                'port' => 3306,
                'memory' => true,
            ],
            [
                'db.system' => 'foo_platform',
                'db.user' => 'root',
                'db.name' => 'INFORMATION_SCHEMA',
                'server.port' => '3306',
                'server.socket.address' => 'inproc',
            ],
        ];

        yield [
            [
                'host' => 'localhost',
            ],
            [
                'db.system' => 'foo_platform',
                'server.address' => 'localhost',
            ],
        ];

        yield [
            [
                'host' => '127.0.0.1',
            ],
            [
                'db.system' => 'foo_platform',
                'server.address' => '127.0.0.1',
            ],
        ];
    }
}
