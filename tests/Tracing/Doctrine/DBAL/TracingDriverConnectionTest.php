<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Tracing\Doctrine\DBAL;

use Doctrine\DBAL\Driver\Connection as DriverConnectionInterface;
use Doctrine\DBAL\Driver\Result as DriverResultInterface;
use Doctrine\DBAL\Driver\Statement as DriverStatementInterface;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\MockObject\MockObject;
use Sentry\SentryBundle\Tests\DoctrineTestCase;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriverConnection;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingStatement;
use Sentry\State\HubInterface;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;

final class TracingDriverConnectionTest extends DoctrineTestCase
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
        if (!self::isDoctrineBundlePackageInstalled()) {
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
     * @dataProvider tagsDataProvider
     *
     * @param array<string, mixed>  $params
     * @param array<string, string> $expectedTags
     */
    public function testPrepare(array $params, array $expectedTags): void
    {
        $sql = 'SELECT 1 + 1';
        $statement = $this->createMock(DriverStatementInterface::class);
        $resultStatement = new TracingStatement($this->hub, $statement, $sql, $expectedTags);
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

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertSame(TracingDriverConnection::SPAN_OP_CONN_PREPARE, $spans[1]->getOp());
        $this->assertSame($sql, $spans[1]->getDescription());
        $this->assertSame($expectedTags, $spans[1]->getTags());
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
     * @dataProvider tagsDataProvider
     *
     * @param array<string, mixed>  $params
     * @param array<string, string> $expectedTags
     */
    public function testQuery(array $params, array $expectedTags): void
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

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertSame(TracingDriverConnection::SPAN_OP_CONN_QUERY, $spans[1]->getOp());
        $this->assertSame($sql, $spans[1]->getDescription());
        $this->assertSame($expectedTags, $spans[1]->getTags());
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
            ->with('foo', ParameterType::STRING)
            ->willReturn('foo');

        $this->assertSame('foo', $this->connection->quote('foo'));
    }

    /**
     * @dataProvider tagsDataProvider
     *
     * @param array<string, mixed>  $params
     * @param array<string, string> $expectedTags
     */
    public function testExec(array $params, array $expectedTags): void
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

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertSame(TracingDriverConnection::SPAN_OP_CONN_EXEC, $spans[1]->getOp());
        $this->assertSame($sql, $spans[1]->getDescription());
        $this->assertSame($expectedTags, $spans[1]->getTags());
        $this->assertNotNull($spans[1]->getEndTimestamp());
    }

    public function testLastInsertId(): void
    {
        $this->decoratedConnection->expects($this->once())
            ->method('lastInsertId')
            ->with('foo')
            ->willReturn('10');

        $this->assertSame('10', $this->connection->lastInsertId('foo'));
    }

    /**
     * @dataProvider tagsDataProvider
     *
     * @param array<string, mixed>  $params
     * @param array<string, string> $expectedTags
     */
    public function testBeginTransaction(array $params, array $expectedTags): void
    {
        $connection = new TracingDriverConnection($this->hub, $this->decoratedConnection, 'foo_platform', $params);
        $transaction = new Transaction(new TransactionContext(), $this->hub);
        $transaction->initSpanRecorder();

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($transaction);

        $this->decoratedConnection->expects($this->once())
            ->method('beginTransaction')
            ->willReturn(false);

        $this->assertFalse($connection->beginTransaction());

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertSame(TracingDriverConnection::SPAN_OP_CONN_BEGIN_TRANSACTION, $spans[1]->getOp());
        $this->assertSame('BEGIN TRANSACTION', $spans[1]->getDescription());
        $this->assertSame($expectedTags, $spans[1]->getTags());
        $this->assertNotNull($spans[1]->getEndTimestamp());
    }

    public function testBeginTransactionDoesNothingIfNoSpanIsSetOnHub(): void
    {
        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn(null);

        $this->decoratedConnection->expects($this->once())
            ->method('beginTransaction')
            ->willReturn(false);

        $this->assertFalse($this->connection->beginTransaction());
    }

    /**
     * @dataProvider tagsDataProvider
     *
     * @param array<string, mixed>  $params
     * @param array<string, string> $expectedTags
     */
    public function testCommit(array $params, array $expectedTags): void
    {
        $connection = new TracingDriverConnection($this->hub, $this->decoratedConnection, 'foo_platform', $params);
        $transaction = new Transaction(new TransactionContext(), $this->hub);
        $transaction->initSpanRecorder();

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($transaction);

        $this->decoratedConnection->expects($this->once())
            ->method('commit')
            ->willReturn(false);

        $this->assertFalse($connection->commit());

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertSame(TracingDriverConnection::SPAN_OP_TRANSACTION_COMMIT, $spans[1]->getOp());
        $this->assertSame('COMMIT', $spans[1]->getDescription());
        $this->assertSame($expectedTags, $spans[1]->getTags());
        $this->assertNotNull($spans[1]->getEndTimestamp());
    }

    public function testCommitDoesNothingIfNoSpanIsSetOnHub(): void
    {
        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn(null);

        $this->decoratedConnection->expects($this->once())
            ->method('commit')
            ->willReturn(false);

        $this->assertFalse($this->connection->commit());
    }

    /**
     * @dataProvider tagsDataProvider
     *
     * @param array<string, mixed>  $params
     * @param array<string, string> $expectedTags
     */
    public function testRollBack(array $params, array $expectedTags): void
    {
        $connection = new TracingDriverConnection($this->hub, $this->decoratedConnection, 'foo_platform', $params);
        $transaction = new Transaction(new TransactionContext(), $this->hub);
        $transaction->initSpanRecorder();

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($transaction);

        $this->decoratedConnection->expects($this->once())
            ->method('rollBack')
            ->willReturn(false);

        $this->assertFalse($connection->rollBack());

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertSame(TracingDriverConnection::SPAN_OP_TRANSACTION_ROLLBACK, $spans[1]->getOp());
        $this->assertSame('ROLLBACK', $spans[1]->getDescription());
        $this->assertSame($expectedTags, $spans[1]->getTags());
        $this->assertNotNull($spans[1]->getEndTimestamp());
    }

    public function testRollBackDoesNothingIfNoSpanIsSetOnHub(): void
    {
        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn(null);

        $this->decoratedConnection->expects($this->once())
            ->method('rollBack')
            ->willReturn(false);

        $this->assertFalse($this->connection->rollBack());
    }

    public function testGetWrappedConnection(): void
    {
        $connection = new TracingDriverConnection($this->hub, $this->decoratedConnection, 'foo_platform', []);

        $this->assertSame($this->decoratedConnection, $connection->getWrappedConnection());
    }

    /**
     * @return \Generator<mixed>
     */
    public function tagsDataProvider(): \Generator
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
                'net.peer.port' => '3306',
                'net.transport' => 'Unix',
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
                'net.peer.port' => '3306',
                'net.transport' => 'inproc',
            ],
        ];

        yield [
            [
                'host' => 'localhost',
            ],
            [
                'db.system' => 'foo_platform',
                'net.peer.name' => 'localhost',
            ],
        ];

        yield [
            [
                'host' => '127.0.0.1',
            ],
            [
                'db.system' => 'foo_platform',
                'net.peer.ip' => '127.0.0.1',
            ],
        ];
    }
}
