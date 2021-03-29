<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\EventListener;

use Generator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\EventListener\TracingConsoleListener;
use Sentry\State\HubInterface;
use Sentry\Tracing\Span;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class TracingConsoleListenerTest extends TestCase
{
    /**
     * @var MockObject&HubInterface
     */
    private $hub;

    /**
     * @var TracingConsoleListener
     */
    private $listener;

    protected function setUp(): void
    {
        $this->hub = $this->createMock(HubInterface::class);
        $this->listener = new TracingConsoleListener($this->hub);
    }

    /**
     * @dataProvider handleConsoleCommandEventStartsTransactionIfNoSpanIsSetOnHubDataProvider
     */
    public function testHandleConsoleCommandEventStartsTransactionIfNoSpanIsSetOnHub(?Command $command, TransactionContext $expectedTransactionContext): void
    {
        $transaction = new Transaction(new TransactionContext());

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn(null);

        $this->hub->expects($this->once())
            ->method('startTransaction')
            ->with($this->callback(function (TransactionContext $context) use ($expectedTransactionContext): bool {
                $this->assertEquals($expectedTransactionContext, $context);

                return true;
            }))
            ->willReturn($transaction);

        $this->hub->expects($this->once())
            ->method('setSpan')
            ->with($transaction)
            ->willReturnSelf();

        $this->listener->handleConsoleCommandEvent(new ConsoleCommandEvent(
            $command,
            $this->createMock(InputInterface::class),
            $this->createMock(OutputInterface::class)
        ));
    }

    /**
     * @return Generator<mixed>
     */
    public function handleConsoleCommandEventStartsTransactionIfNoSpanIsSetOnHubDataProvider(): Generator
    {
        $transactionContext = new TransactionContext();
        $transactionContext->setOp('console.command');
        $transactionContext->setName('<unnamed command>');

        yield [
            null,
            $transactionContext,
        ];

        $transactionContext = new TransactionContext();
        $transactionContext->setOp('console.command');
        $transactionContext->setName('<unnamed command>');

        yield [
            new Command(),
            $transactionContext,
        ];

        $transactionContext = new TransactionContext();
        $transactionContext->setOp('console.command');
        $transactionContext->setName('app:command');

        yield [
            new Command('app:command'),
            $transactionContext,
        ];
    }

    /**
     * @dataProvider handleConsoleCommandEventStartsChildSpanIfSpanIsSetOnHubDataProvider
     */
    public function testHandleConsoleCommandEventStartsChildSpanIfSpanIsSetOnHub(?Command $command, string $expectedDescription): void
    {
        $span = new Span();

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($span);

        $this->hub->expects($this->once())
            ->method('setSpan')
            ->with($this->callback(function (Span $spanArg) use ($span, $expectedDescription): bool {
                $this->assertSame('console.command', $spanArg->getOp());
                $this->assertSame($expectedDescription, $spanArg->getDescription());
                $this->assertSame($span->getSpanId(), $spanArg->getParentSpanId());

                return true;
            }))
            ->willReturnSelf();

        $this->listener->handleConsoleCommandEvent(new ConsoleCommandEvent(
            $command,
            $this->createMock(InputInterface::class),
            $this->createMock(OutputInterface::class)
        ));
    }

    /**
     * @return Generator<mixed>
     */
    public function handleConsoleCommandEventStartsChildSpanIfSpanIsSetOnHubDataProvider(): Generator
    {
        yield [
            null,
            '<unnamed command>',
        ];

        yield [
            new Command(),
            '<unnamed command>',
        ];

        yield [
            new Command('app:command'),
            'app:command',
        ];
    }

    public function testHandleConsoleTerminateEvent(): void
    {
        $span = new Span();

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($span);

        $this->listener->handleConsoleTerminateEvent(new ConsoleTerminateEvent(
            new Command(),
            $this->createMock(InputInterface::class),
            $this->createMock(OutputInterface::class),
            0
        ));

        $this->assertNotNull($span->getEndTimestamp());
    }

    public function testHandleConsoleTerminateEventDoesNothingIfNoSpanIsSetOnHub(): void
    {
        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn(null);

        $this->listener->handleConsoleTerminateEvent(new ConsoleTerminateEvent(
            new Command(),
            $this->createMock(InputInterface::class),
            $this->createMock(OutputInterface::class),
            0
        ));
    }
}
