<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Tracing;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\Tracing\DbalSqlTracingLogger;
use Sentry\State\HubInterface;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;

final class DbalSqlTracingLoggerTest extends TestCase
{
    /**
     * @var MockObject&HubInterface
     */
    private $hub;

    /**
     * @var DbalSqlTracingLogger
     */
    private $logger;

    protected function setUp(): void
    {
        $this->hub = $this->createMock(HubInterface::class);
        $this->logger = new DbalSqlTracingLogger($this->hub);
    }

    public function testStopQueryDoesNothingIfTransactionDidNotStartTheChildSpan(): void
    {
        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn(null);

        $this->logger->startQuery('SELECT * FROM orders');
        $this->logger->stopQuery();
    }

    public function testStopQueryFinishesTheChildSpan(): void
    {
        $transaction = new Transaction(new TransactionContext());
        $transaction->initSpanRecorder();

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($transaction);

        $this->logger->startQuery('SELECT * FROM orders');

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertNull($spans[1]->getEndTimestamp());

        $this->logger->stopQuery();

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertNotNull($spans[1]->getEndTimestamp());
    }
}
