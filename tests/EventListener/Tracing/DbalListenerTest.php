<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\EventListener\Tracing;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\EventListener\Tracing\DbalListener;
use Sentry\State\HubInterface;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;

final class DbalListenerTest extends TestCase
{
    /**
     * @var MockObject&HubInterface
     */
    private $hub;

    /**
     * @var DbalListener
     */
    private $listener;

    protected function setUp(): void
    {
        $this->hub = $this->createMock(HubInterface::class);
        $this->listener = new DbalListener($this->hub);
    }

    public function testThatDbalStartQueryIgnoresTracingWhenTransactionIsNotStarted(): void
    {
        $this->hub->expects($this->once())
            ->method('getTransaction')
            ->willReturn(null);

        $this->listener->startQuery('');
    }

    public function testThatDbalStopQueryIgnoresTracingWhenChildSpanWasNotStarted(): void
    {
        $this->hub->expects($this->once())
            ->method('getTransaction')
            ->willReturn(null);

        $this->listener->startQuery('');
        $this->listener->stopQuery();
    }

    public function testThatDbalStartQueryAttachesAChildSpanWhenTransactionStarted(): void
    {
        $transaction = new Transaction(new TransactionContext());
        $transaction->initSpanRecorder();

        $this->hub->expects($this->once())
            ->method('getTransaction')
            ->willReturn($transaction);

        $this->listener->startQuery('');

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertNull($spans['1']->getEndTimestamp());
    }

    public function testThatDbalStopQueryFinishesTheChildSpanWhenChildSpanStarted(): void
    {
        $transaction = new Transaction(new TransactionContext());
        $transaction->initSpanRecorder();

        $this->hub->expects($this->once())
            ->method('getTransaction')
            ->willReturn($transaction);

        $this->listener->startQuery('');
        $this->listener->stopQuery();

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertNotNull($spans['1']->getEndTimestamp());
    }
}
