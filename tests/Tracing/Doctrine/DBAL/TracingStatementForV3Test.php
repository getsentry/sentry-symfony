<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Tracing\Doctrine\DBAL;

use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\MockObject\MockObject;
use Sentry\ClientInterface;
use Sentry\Options;
use Sentry\SentryBundle\Tests\DoctrineTestCase;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingStatementForV3;
use Sentry\State\HubInterface;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;

final class TracingStatementForV3Test extends DoctrineTestCase
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
     * @var TracingStatementForV3
     */
    private $statement;

    public static function setUpBeforeClass(): void
    {
        if (!self::isDoctrineDBALVersion3Installed()) {
            self::markTestSkipped('This test requires the version of the "doctrine/dbal" Composer package to be >= 3.3.');
        }
    }

    protected function setUp(): void
    {
        $this->hub = $this->createMock(HubInterface::class);
        $this->decoratedStatement = $this->createMock(Statement::class);
        $this->statement = new TracingStatementForV3($this->hub, $this->decoratedStatement, 'SELECT 1', ['db.system' => 'sqlite']);
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
        $decoratedStatement = new class implements Statement {
            /**
             * @var int
             */
            public $bindParamCallArgsCount = 0;

            public function bindValue($param, $value, $type = ParameterType::STRING): bool
            {
                throw new \BadMethodCallException();
            }

            public function bindParam($param, &$variable, $type = ParameterType::STRING, $length = null): bool
            {
                // Since PHPUnit forcefully calls the mocked methods with all
                // parameters, regardless of whether they were originally passed
                // in an explicit manner, we can't use a mock to assert the number
                // of args used in the call to the function
                $this->bindParamCallArgsCount = \func_num_args();

                return true;
            }

            public function execute($params = null): Result
            {
                throw new \BadMethodCallException();
            }
        };

        $this->statement = new TracingStatementForV3($this->hub, $decoratedStatement, 'SELECT 1', ['db.system' => 'sqlite']);

        $this->assertTrue($this->statement->bindParam('foo', $variable, ParameterType::INTEGER));
        $this->assertSame(3, $decoratedStatement->bindParamCallArgsCount);
    }

    public function testExecute(): void
    {
        $driverResult = $this->createMock(Result::class);
        $transaction = new Transaction(new TransactionContext(), $this->hub);
        $transaction->initSpanRecorder();

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($transaction);

        $this->decoratedStatement->expects($this->once())
            ->method('execute')
            ->with(['foo' => 'bar'])
            ->willReturn($driverResult);

        $this->assertSame($driverResult, $this->statement->execute(['foo' => 'bar']));
        $this->assertNotNull($transaction->getSpanRecorder());

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertSame(TracingStatementForV3::SPAN_OP_STMT_EXECUTE, $spans[1]->getOp());
        $this->assertSame('SELECT 1', $spans[1]->getDescription());
        $this->assertSame(['db.system' => 'sqlite'], $spans[1]->getData());
        $this->assertNotNull($spans[1]->getEndTimestamp());
    }

    public function testExecuteDoesNothingIfNoSpanIsSetOnHub(): void
    {
        $driverResult = $this->createMock(Result::class);

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn(null);

        $this->decoratedStatement->expects($this->once())
            ->method('execute')
            ->with(['foo' => 'bar'])
            ->willReturn($driverResult);

        $this->assertSame($driverResult, $this->statement->execute(['foo' => 'bar']));
    }

    public function testExecuteCollectsBoundParameters(): void
    {
        $transaction = $this->createSampledTransactionWithDataCollection();

        $this->decoratedStatement->method('bindValue')->willReturn(true);
        $this->decoratedStatement->method('bindParam')->willReturn(true);
        $this->decoratedStatement->method('execute')->willReturn($this->createMock(Result::class));

        $variable = 'foo';

        $this->statement->bindParam(1, $variable);
        $this->statement->bindValue('password', 'secret');

        $variable = 'bar';

        $this->statement->execute();

        $this->assertSame([
            'db.system' => 'sqlite',
            'db.query.parameter.0' => 'bar',
            'db.query.parameter.password' => '[Filtered]',
        ], $this->getExecutedSpanData($transaction));
    }

    public function testExecuteCollectsParametersPassedToIt(): void
    {
        $transaction = $this->createSampledTransactionWithDataCollection();

        $this->decoratedStatement->method('bindValue')->willReturn(true);
        $this->decoratedStatement->method('execute')->willReturn($this->createMock(Result::class));

        $this->statement->bindValue(1, 'foo');
        $this->statement->execute(['bar', 'baz']);

        $this->assertSame([
            'db.system' => 'sqlite',
            'db.query.parameter.0' => 'bar',
            'db.query.parameter.1' => 'baz',
        ], $this->getExecutedSpanData($transaction));
    }

    public function testBindValueDoesNotChangeAVariableBoundByReference(): void
    {
        $this->decoratedStatement->method('bindValue')->willReturn(true);
        $this->decoratedStatement->method('bindParam')->willReturn(true);

        $variable = 'foo';

        $this->statement->bindParam(1, $variable);
        $this->statement->bindValue(1, 'bar');

        $this->assertSame('foo', $variable);
    }

    private function createSampledTransactionWithDataCollection(): Transaction
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->any())
            ->method('getOptions')
            ->willReturn(new Options(['data_collection' => []]));

        $transactionContext = new TransactionContext();
        $transactionContext->setSampled(true);

        $transaction = new Transaction($transactionContext, $this->hub);
        $transaction->initSpanRecorder();

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($transaction);

        $this->hub->expects($this->any())
            ->method('getClient')
            ->willReturn($client);

        return $transaction;
    }

    /**
     * @return array<string, mixed>
     */
    private function getExecutedSpanData(Transaction $transaction): array
    {
        $this->assertNotNull($transaction->getSpanRecorder());

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);

        return $spans[1]->getData();
    }
}
