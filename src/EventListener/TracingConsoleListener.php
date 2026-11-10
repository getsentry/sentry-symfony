<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\EventListener;

use Sentry\SentryBundle\Sentry;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Sentry\Tracing\TransactionSource;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;

/**
 * This listener either starts a {@see Transaction} or a child {@see Span} when
 * a console command is executed to allow measuring the application performances.
 */
final class TracingConsoleListener
{
    /**
     * @var Sentry
     */
    private $sentry;

    /**
     * @var string[] The list of commands for which distributed tracing must be skipped
     */
    private $excludedCommands;

    /**
     * Constructor.
     *
     * @param Sentry   $sentry
     * @param string[] $excludedCommands The list of commands for which distributed tracing must be skipped
     */
    public function __construct(Sentry $sentry, array $excludedCommands = [])
    {
        $this->sentry = $sentry;
        $this->excludedCommands = $excludedCommands;
    }

    /**
     * Handles the execution of a console command by starting a new {@see Transaction}
     * if it doesn't exists, or a child {@see Span} if it does.
     *
     * @param ConsoleCommandEvent $event The event
     */
    public function handleConsoleCommandEvent(ConsoleCommandEvent $event): void
    {
        $command = $event->getCommand();

        if ($this->isCommandExcluded($command)) {
            return;
        }

        $currentSpan = $this->sentry->getSpan();

        if (null === $currentSpan) {
            $span = $this->sentry->startTransaction(
                TransactionContext::make()
                    ->setOp('console.command')
                    ->setOrigin('auto.console')
                    ->setName($this->getSpanName($command))
                    ->setSource(TransactionSource::task())
            );
        } else {
            $span = $currentSpan->startChild(
                SpanContext::make()
                    ->setOp('console.command')
                    ->setOrigin('auto.console')
                    ->setDescription($this->getSpanName($command))
            );
        }

        $this->sentry->setSpan($span);
    }

    /**
     * Handles the termination of a console command by stopping the active {@see Span}
     * or {@see Transaction}.
     *
     * @param ConsoleTerminateEvent $event The event
     */
    public function handleConsoleTerminateEvent(ConsoleTerminateEvent $event): void
    {
        if ($this->isCommandExcluded($event->getCommand())) {
            return;
        }

        $span = $this->sentry->getSpan();

        if (null !== $span) {
            $span->setStatus(0 === $event->getExitCode() ? SpanStatus::ok() : SpanStatus::internalError());
            $span->finish();
        }
    }

    private function getSpanName(?Command $command): string
    {
        if (null === $command || null === $command->getName()) {
            return '<unnamed command>';
        }

        return $command->getName();
    }

    private function isCommandExcluded(?Command $command): bool
    {
        if (null === $command) {
            return true;
        }

        return \in_array($command->getName(), $this->excludedCommands, true);
    }
}
