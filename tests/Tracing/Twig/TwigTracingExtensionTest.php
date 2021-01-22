<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Tracing\Twig;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\Tracing\Twig\TwigTracingExtension;
use Sentry\State\HubInterface;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Twig\Profiler\Profile;

final class TwigTracingExtensionTest extends TestCase
{
    /**
     * @var MockObject&HubInterface
     */
    private $hub;

    /**
     * @var TwigTracingExtension
     */
    private $listener;

    protected function setUp(): void
    {
        $this->hub = $this->createMock(HubInterface::class);
        $this->listener = new TwigTracingExtension($this->hub);
    }

    public function testThatTwigEnterProfileIgnoresTracingWhenTransactionIsNotStarted(): void
    {
        $this->hub->expects($this->once())
            ->method('getTransaction')
            ->willReturn(null);

        $this->listener->enter(new Profile('main', Profile::TEMPLATE));
    }

    public function testThatTwigEnterProfileIgnoresTracingWhenNotATemplate(): void
    {
        $this->hub->expects($this->once())
            ->method('getTransaction')
            ->willReturn(new Transaction(new TransactionContext()));

        $this->listener->enter(new Profile('main', Profile::ROOT));
    }

    public function testThatTwigLeaveProfileIgnoresTracingWhenTransactionIsNotStarted(): void
    {
        $this->hub->expects($this->once())
            ->method('getTransaction')
            ->willReturn(null);

        $profile = new Profile('main', Profile::TEMPLATE);

        $this->listener->enter($profile);
        $this->listener->leave($profile);
    }

    public function testThatTwigLeaveProfileIgnoresTracingWhenNotATemplate(): void
    {
        $this->hub->expects($this->once())
            ->method('getTransaction')
            ->willReturn(new Transaction(new TransactionContext()));

        $profile = new Profile('main', Profile::ROOT);

        $this->listener->enter($profile);
        $this->listener->leave($profile);
    }

    public function testThatTwigEnterProfileAttachesAChildSpanWhenTransactionStarted(): void
    {
        $transaction = new Transaction(new TransactionContext());
        $transaction->initSpanRecorder();

        $this->hub->expects($this->once())
            ->method('getTransaction')
            ->willReturn($transaction);

        $this->listener->enter(new Profile('main', Profile::TEMPLATE));

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertNull($spans[1]->getEndTimestamp());
    }

    public function testThatTwigLeaveProfileFinishesTheChildSpanWhenChildSpanStarted(): void
    {
        $transaction = new Transaction(new TransactionContext());
        $transaction->initSpanRecorder();

        $this->hub->expects($this->once())
            ->method('getTransaction')
            ->willReturn($transaction);

        $profile = new Profile('main', Profile::TEMPLATE);

        $this->listener->enter($profile);
        $this->listener->leave($profile);

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertNotNull($spans[1]->getEndTimestamp());
    }
}
