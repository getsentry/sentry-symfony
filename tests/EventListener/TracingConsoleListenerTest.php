<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\EventListener;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\EventListener\TracingConsoleListener;
use Sentry\SentryBundle\SentryManager;
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
     * @var MockObject&SentryManager
     */
    private $sentry;

    protected function setUp(): void
    {
        $this->sentry = $this->createMock(SentryManager::class);
    }

    /**
     * @dataProvider handleConsoleCommandEventStartsTransactionIfNoSpanIsSetOnScopeDataProvider
     */
    public function testHandleConsoleCommandEventStartsTransactionIfNoSpanIsSetOnScope(Command $command, TransactionContext $expectedTransactionContext): void
    {
        $transaction = new Transaction(new TransactionContext());

        $this->sentry->expects($this->once())
            ->method('getSpan')
            ->willReturn(null);

        $this->sentry->expects($this->once())
            ->method('startTransaction')
            ->with($this->callback(function (TransactionContext $context) use ($expectedTransactionContext): bool {
                // This value is random when the metadata is constructed, thus we set it to a fixed expected value since we don't care for the value here
                $context->getMetadata()->setSampleRand(0.1337);

                $this->assertEquals($expectedTransactionContext, $context);

                return true;
            }))
            ->willReturn($transaction);

        $this->sentry->expects($this->once())
            ->method('setSpan')
            ->with($transaction)
            ->willReturnSelf();

        $listener = new TracingConsoleListener($this->sentry);
        $listener->handleConsoleCommandEvent(new ConsoleCommandEvent(
            $command,
            new ArrayInput([]),
            new NullOutput()
        ));
    }

    /**
     * @return \Generator<mixed>
     */
    public function handleConsoleCommandEventStartsTransactionIfNoSpanIsSetOnScopeDataProvider(): \Generator
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
     * @dataProvider handleConsoleCommandEventStartsChildSpanIfSpanIsSetOnScopeDataProvider
     */
    public function testHandleConsoleCommandEventStartsChildSpanIfSpanIsSetOnScope(Command $command, string $expectedDescription): void
    {
        $span = new Span();

        $this->sentry->expects($this->once())
            ->method('getSpan')
            ->willReturn($span);

        $this->sentry->expects($this->once())
            ->method('setSpan')
            ->with($this->callback(function (Span $spanArg) use ($span, $expectedDescription): bool {
                $this->assertSame('console.command', $spanArg->getOp());
                $this->assertSame($expectedDescription, $spanArg->getDescription());
                $this->assertSame($span->getSpanId(), $spanArg->getParentSpanId());

                return true;
            }))
            ->willReturnSelf();

        $listener = new TracingConsoleListener($this->sentry);
        $listener->handleConsoleCommandEvent(new ConsoleCommandEvent(
            $command,
            new ArrayInput([]),
            new NullOutput()
        ));
    }

    /**
     * @return \Generator<mixed>
     */
    public function handleConsoleCommandEventStartsChildSpanIfSpanIsSetOnScopeDataProvider(): \Generator
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
        $this->sentry->expects($this->never())
            ->method('getSpan');

        $listener = new TracingConsoleListener($this->sentry, ['foo:bar']);
        $listener->handleConsoleCommandEvent(new ConsoleCommandEvent(
            new Command('foo:bar'),
            new ArrayInput([]),
            new NullOutput()
        ));
    }

    public function testHandleConsoleTerminateEvent(): void
    {
        $span = new Span();

        $this->sentry->expects($this->once())
            ->method('getSpan')
            ->willReturn($span);

        $listener = new TracingConsoleListener($this->sentry);
        $listener->handleConsoleTerminateEvent(new ConsoleTerminateEvent(
            new Command(),
            new ArrayInput([]),
            new NullOutput(),
            0
        ));

        $this->assertNotNull($span->getEndTimestamp());
    }

    public function testHandleConsoleTerminateEventDoesNothingIfNoSpanIsSetOnScope(): void
    {
        $this->sentry->expects($this->once())
            ->method('getSpan')
            ->willReturn(null);

        $listener = new TracingConsoleListener($this->sentry);
        $listener->handleConsoleTerminateEvent(new ConsoleTerminateEvent(
            new Command(),
            new ArrayInput([]),
            new NullOutput(),
            0
        ));
    }

    public function testHandleConsoleTerminateEventDoesNothingIfCommandIsExcluded(): void
    {
        $this->sentry->expects($this->never())
            ->method('getSpan');

        $listener = new TracingConsoleListener($this->sentry, ['foo:bar']);
        $listener->handleConsoleTerminateEvent(new ConsoleTerminateEvent(
            new Command('foo:bar'),
            new ArrayInput([]),
            new NullOutput(),
            0
        ));
    }
}
