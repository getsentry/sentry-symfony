<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\EventListener;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\EventListener\TracingConsoleListener;
use Sentry\State\HubInterface;
use Sentry\Tracing\Span;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Sentry\Tracing\TransactionSource;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

final class TracingConsoleListenerTest extends TestCase
{
    /**
     * @var MockObject&HubInterface
     */
    private $hub;

    protected function setUp(): void
    {
        $this->hub = $this->createMock(HubInterface::class);
    }

    /**
     * @dataProvider handleConsoleCommandEventStartsTransactionIfNoSpanIsSetOnHubDataProvider
     */
    public function testHandleConsoleCommandEventStartsTransactionIfNoSpanIsSetOnHub(Command $command, TransactionContext $expectedTransactionContext): void
    {
        $transaction = new Transaction(new TransactionContext());

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn(null);

        $this->hub->expects($this->once())
            ->method('startTransaction')
            ->with($this->callback(function (TransactionContext $context) use ($expectedTransactionContext): bool {
                // This value is random when the metadata is constructed, thus we set it to a fixed expected value since we don't care for the value here
                $context->getMetadata()->setSampleRand(0.1337);

                $this->assertEquals($expectedTransactionContext, $context);

                return true;
            }))
            ->willReturn($transaction);

        $this->hub->expects($this->once())
            ->method('setSpan')
            ->with($transaction)
            ->willReturnSelf();

        $listener = new TracingConsoleListener($this->hub);
        $listener->handleConsoleCommandEvent(new ConsoleCommandEvent(
            $command,
            new ArrayInput([]),
            new NullOutput()
        ));
    }

    /**
     * @return \Generator<mixed>
     */
    public function handleConsoleCommandEventStartsTransactionIfNoSpanIsSetOnHubDataProvider(): \Generator
    {
        $transactionContext = new TransactionContext();
        $transactionContext->setOp('console.command');
        $transactionContext->setName('<unnamed command>');
        $transactionContext->setOrigin('auto.console');
        $transactionContext->setSource(TransactionSource::task());
        $transactionContext->getMetadata()->setSampleRand(0.1337);

        yield [
            new Command(),
            $transactionContext,
        ];

        $transactionContext = new TransactionContext();
        $transactionContext->setOp('console.command');
        $transactionContext->setName('app:command');
        $transactionContext->setOrigin('auto.console');
        $transactionContext->setSource(TransactionSource::task());
        $transactionContext->getMetadata()->setSampleRand(0.1337);

        yield [
            new Command('app:command'),
            $transactionContext,
        ];
    }

    /**
     * @dataProvider handleConsoleCommandEventStartsChildSpanIfSpanIsSetOnHubDataProvider
     */
    public function testHandleConsoleCommandEventStartsChildSpanIfSpanIsSetOnHub(Command $command, string $expectedDescription): void
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

        $listener = new TracingConsoleListener($this->hub);
        $listener->handleConsoleCommandEvent(new ConsoleCommandEvent(
            $command,
            new ArrayInput([]),
            new NullOutput()
        ));
    }

    /**
     * @return \Generator<mixed>
     */
    public function handleConsoleCommandEventStartsChildSpanIfSpanIsSetOnHubDataProvider(): \Generator
    {
        yield [
            new Command(),
            '<unnamed command>',
        ];

        yield [
            new Command('app:command'),
            'app:command',
        ];
    }

    public function testHandleConsoleCommandEvent(): void
    {
        $this->hub->expects($this->never())
            ->method('getSpan');

        $listener = new TracingConsoleListener($this->hub, ['foo:bar']);
        $listener->handleConsoleCommandEvent(new ConsoleCommandEvent(
            new Command('foo:bar'),
            new ArrayInput([]),
            new NullOutput()
        ));
    }

    public function testHandleConsoleTerminateEvent(): void
    {
        $span = new Span();

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($span);

        $listener = new TracingConsoleListener($this->hub);
        $listener->handleConsoleTerminateEvent(new ConsoleTerminateEvent(
            new Command(),
            new ArrayInput([]),
            new NullOutput(),
            0
        ));

        $this->assertNotNull($span->getEndTimestamp());
    }

    public function testHandleConsoleTerminateEventDoesNothingIfNoSpanIsSetOnHub(): void
    {
        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn(null);

        $listener = new TracingConsoleListener($this->hub);
        $listener->handleConsoleTerminateEvent(new ConsoleTerminateEvent(
            new Command(),
            new ArrayInput([]),
            new NullOutput(),
            0
        ));
    }

    public function testHandleConsoleTerminateEventDoesNothingIfCommandIsExcluded(): void
    {
        $this->hub->expects($this->never())
            ->method('getSpan');

        $listener = new TracingConsoleListener($this->hub, ['foo:bar']);
        $listener->handleConsoleTerminateEvent(new ConsoleTerminateEvent(
            new Command('foo:bar'),
            new ArrayInput([]),
            new NullOutput(),
            0
        ));
    }
}
