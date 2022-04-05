<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Tracing\Doctrine\DBAL;

use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\FetchMode;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\MockObject\MockObject;
use Sentry\SentryBundle\Tests\DoctrineTestCase;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingStatementForV2;
use Sentry\State\HubInterface;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;

final class TracingStatementForV2Test extends DoctrineTestCase
{
    /**
     * @var MockObject&HubInterface
     */
    private $hub;

    /**
     * @var Statement&MockObject
     */
    private $decoratedStatement;

    /**
     * @var TracingStatementForV2
     */
    private $statement;

    public static function setUpBeforeClass(): void
    {
        if (!self::isDoctrineDBALVersion2Installed()) {
            self::markTestSkipped('This test requires the version of the "doctrine/dbal" Composer package to be ^2.13.');
        }
    }

    protected function setUp(): void
    {
        $this->hub = $this->createMock(HubInterface::class);
        $this->decoratedStatement = $this->createMock(Statement::class);
        $this->statement = new TracingStatementForV2($this->hub, $this->decoratedStatement, 'SELECT 1', ['db.system' => 'sqlite']);
    }

    public function testGetIterator(): void
    {
        $this->assertSame($this->decoratedStatement, $this->statement->getIterator());
    }

    public function testCloseCursor(): void
    {
        $this->decoratedStatement->expects($this->once())
            ->method('closeCursor')
            ->willReturn(true);

        $this->assertTrue($this->statement->closeCursor());
    }

    public function testColumnCount(): void
    {
        $this->decoratedStatement->expects($this->once())
            ->method('columnCount')
            ->willReturn(10);

        $this->assertSame(10, $this->statement->columnCount());
    }

    public function testSetFetchMode(): void
    {
        $this->decoratedStatement->expects($this->once())
            ->method('setFetchMode')
            ->with(FetchMode::COLUMN, 'foo', 'bar')
            ->willReturn(true);

        $this->assertTrue($this->statement->setFetchMode(FetchMode::COLUMN, 'foo', 'bar'));
    }

    public function testFetch(): void
    {
        $this->decoratedStatement->expects($this->once())
            ->method('fetch')
            ->with(FetchMode::COLUMN, \PDO::FETCH_ORI_NEXT, 10)
            ->willReturn('foo');

        $this->assertSame('foo', $this->statement->fetch(FetchMode::COLUMN, \PDO::FETCH_ORI_NEXT, 10));
    }

    public function testFetchAll(): void
    {
        $this->decoratedStatement->expects($this->once())
            ->method('fetchAll')
            ->with(FetchMode::COLUMN, 0, [])
            ->willReturn(['foo']);

        $this->assertSame(['foo'], $this->statement->fetchAll(FetchMode::COLUMN, 0, []));
    }

    public function testFetchColumn(): void
    {
        $this->decoratedStatement->expects($this->once())
            ->method('fetchColumn')
            ->willReturn('foo');

        $this->assertSame('foo', $this->statement->fetchColumn());
    }

    public function testBindValue(): void
    {
        $this->decoratedStatement->expects($this->once())
            ->method('bindValue')
            ->with('foo', 'bar', ParameterType::INTEGER)
            ->willReturn(true);

        $this->assertTrue($this->statement->bindValue('foo', 'bar', ParameterType::INTEGER));
    }

    public function testBindParam(): void
    {
        $variable = 'bar';

        $this->decoratedStatement->expects($this->once())
            ->method('bindParam')
            ->with('foo', $variable, ParameterType::INTEGER, 10)
            ->willReturn(true);

        $this->assertTrue($this->statement->bindParam('foo', $variable, ParameterType::INTEGER, 10));
    }

    public function testBindParamForwardsLengthParamOnlyWhenExplicitlySet(): void
    {
        $variable = 'bar';
        $decoratedStatement = $this->createPartialMock(TracingStatementForV2Stub::class, array_diff(get_class_methods(TracingStatementForV2Stub::class), ['bindParam']));

        $this->statement = new TracingStatementForV2($this->hub, $decoratedStatement, 'SELECT 1', ['db.system' => 'sqlite']);

        $this->assertTrue($this->statement->bindParam('foo', $variable, ParameterType::INTEGER));
        $this->assertSame(4, $decoratedStatement->bindParamCallArgsCount);
        $this->assertSame(0, $decoratedStatement->bindParamLengthValue);

        $this->assertTrue($this->statement->bindParam('foo', $variable, ParameterType::STRING, 3));
        $this->assertSame(3, $decoratedStatement->bindParamLengthValue);
    }

    public function testErrorCode(): void
    {
        $this->decoratedStatement->expects($this->once())
            ->method('errorCode')
            ->willReturn(false);

        $this->assertFalse($this->statement->errorCode());
    }

    public function testErrorInfo(): void
    {
        $this->decoratedStatement->expects($this->once())
            ->method('errorInfo')
            ->willReturn(['foo' => 'bar']);

        $this->assertSame(['foo' => 'bar'], $this->statement->errorInfo());
    }

    public function testExecute(): void
    {
        $transaction = new Transaction(new TransactionContext(), $this->hub);
        $transaction->initSpanRecorder();

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($transaction);

        $this->decoratedStatement->expects($this->once())
            ->method('execute')
            ->with(['foo' => 'bar'])
            ->willReturn(true);

        $this->assertTrue($this->statement->execute(['foo' => 'bar']));
        $this->assertNotNull($transaction->getSpanRecorder());

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertSame(TracingStatementForV2::SPAN_OP_STMT_EXECUTE, $spans[1]->getOp());
        $this->assertSame('SELECT 1', $spans[1]->getDescription());
        $this->assertSame(['db.system' => 'sqlite'], $spans[1]->getTags());
        $this->assertNotNull($spans[1]->getEndTimestamp());
    }

    public function testExecuteDoesNothingIfNoSpanIsSetOnHub(): void
    {
        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn(null);

        $this->decoratedStatement->expects($this->once())
            ->method('execute')
            ->with(['foo' => 'bar'])
            ->willReturn(true);

        $this->assertTrue($this->statement->execute(['foo' => 'bar']));
    }

    public function testRowCount(): void
    {
        $this->decoratedStatement->expects($this->once())
            ->method('rowCount')
            ->willReturn(10);

        $this->assertSame(10, $this->statement->rowCount());
    }
}

if (!interface_exists(Statement::class)) {
    abstract class TracingStatementForV2Stub
    {
        /**
         * @var int|null
         */
        public $bindParamLengthValue;
    }
} else {
    /**
     * @phpstan-implements \IteratorAggregate<mixed, mixed>
     */
    abstract class TracingStatementForV2Stub implements \IteratorAggregate, Statement
    {
        /**
         * @var int
         */
        public $bindParamCallArgsCount = 0;
        /**
         * @var int|null
         */
        public $bindParamLengthValue;

        public function bindParam($param, &$variable, $type = ParameterType::STRING, $length = null): bool
        {
            // Since PHPUnit forcefully calls the mocked methods with all
            // parameters, regardless of whether they were originally passed
            // in an explicit manner, we can't use a mock to assert the number
            // of args used in the call to the function
            $this->bindParamCallArgsCount = \func_num_args();
            $this->bindParamLengthValue = $length;

            return true;
        }
    }
}
