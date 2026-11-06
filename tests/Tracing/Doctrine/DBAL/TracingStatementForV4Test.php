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
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingStatementForV4;
use Sentry\State\HubInterface;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;

final class TracingStatementForV4Test extends DoctrineTestCase
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
     * @var TracingStatementForV4
     */
    private $statement;

    public static function setUpBeforeClass(): void
    {
        if (!self::isDoctrineDBALVersion4Installed()) {
            self::markTestSkipped('This test requires the version of the "doctrine/dbal" Composer package to be >= 4.0.');
        }
    }

    protected function setUp(): void
    {
        $this->hub = $this->createMock(HubInterface::class);
        $this->decoratedStatement = $this->createMock(Statement::class);
        $this->statement = new TracingStatementForV4($this->hub, $this->decoratedStatement, 'SELECT 1', ['db.system' => 'sqlite']);
    }

    public function testBindValue(): void
    {
        $this->decoratedStatement->expects($this->once())
            ->method('bindValue')
            ->with('foo', 'bar', ParameterType::INTEGER);

        $this->statement->bindValue('foo', 'bar', ParameterType::INTEGER);
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
            ->willReturn($driverResult);

        $this->assertSame($driverResult, $this->statement->execute());
        $this->assertNotNull($transaction->getSpanRecorder());

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertSame(TracingStatementForV4::SPAN_OP_STMT_EXECUTE, $spans[1]->getOp());
        $this->assertSame('SELECT 1', $spans[1]->getDescription());
        $this->assertSame(['db.system' => 'sqlite'], $spans[1]->getData());
        $this->assertNotNull($spans[1]->getEndTimestamp());
    }

    /**
     * @param array<string, mixed> $expectedData
     *
     * @dataProvider executeCollectsBoundParametersDataProvider
     */
    public function testExecuteCollectsBoundParameters(Options $options, array $expectedData): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->any())
            ->method('getOptions')
            ->willReturn($options);

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

        $this->statement->bindValue(1, 'foo', ParameterType::STRING);
        $this->statement->bindValue(2, 'bar', ParameterType::STRING);
        $this->statement->bindValue(2, 'baz', ParameterType::STRING);
        $this->statement->bindValue('password', 'secret', ParameterType::STRING);
        $this->statement->execute();

        $this->assertNotNull($transaction->getSpanRecorder());

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertSame($expectedData, $spans[1]->getData());
    }

    /**
     * @return \Generator<mixed>
     */
    public function executeCollectsBoundParametersDataProvider(): \Generator
    {
        yield 'The legacy options do not collect query parameters' => [
            new Options(['send_default_pii' => true]),
            ['db.system' => 'sqlite'],
        ];

        yield 'The data collection options collect query parameters by default' => [
            new Options(['data_collection' => []]),
            [
                'db.system' => 'sqlite',
                'db.query.parameter.0' => 'foo',
                'db.query.parameter.1' => 'baz',
                'db.query.parameter.password' => '[Filtered]',
            ],
        ];

        yield 'The data collection options do not collect query parameters if disabled' => [
            new Options(['data_collection' => ['database_query_data' => false]]),
            ['db.system' => 'sqlite'],
        ];
    }

    public function testExecuteDoesNotCollectBoundParametersIfSpanIsNotSampled(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->never())
            ->method('getOptions');

        $transactionContext = new TransactionContext();
        $transactionContext->setSampled(false);

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn(new Transaction($transactionContext, $this->hub));

        $this->hub->expects($this->any())
            ->method('getClient')
            ->willReturn($client);

        $this->decoratedStatement->expects($this->once())
            ->method('execute')
            ->willReturn($this->createMock(Result::class));

        $this->statement->bindValue(1, 'foo', ParameterType::STRING);
        $this->statement->execute();
    }

    public function testExecuteDoesNothingIfNoSpanIsSetOnHub(): void
    {
        $driverResult = $this->createMock(Result::class);

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn(null);

        $this->decoratedStatement->expects($this->once())
            ->method('execute')
            ->willReturn($driverResult);

        $this->assertSame($driverResult, $this->statement->execute());
    }
}
