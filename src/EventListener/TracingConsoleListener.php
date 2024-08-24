<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\EventListener;

use Sentry\State\HubInterface;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Sentry\Tracing\TransactionSource;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;

use function Sentry\metrics;

/**
 * This listener either starts a {@see Transaction} or a child {@see Span} when
 * a console command is executed to allow measuring the application performances.
 */
final class TracingConsoleListener
{
    /**
     * @var HubInterface The current hub
     */
    private $hub;

    /**
     * @var string[] The list of commands for which distributed tracing must be skipped
     */
    private $excludedCommands;

    /**
     * Constructor.
     *
     * @param HubInterface $hub              The current hub
     * @param string[]     $excludedCommands The list of commands for which distributed tracing must be skipped
     */
    public function __construct(HubInterface $hub, array $excludedCommands = [])
    {
        $this->hub = $hub;
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

        $currentSpan = $this->hub->getSpan();

        if (null === $currentSpan) {
            $span = $this->hub->startTransaction(
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

        $this->hub->setSpan($span);
    }

    /**
     * Handles the termination of a console command by stopping the active {@see Span}
     * or {@see Transaction}.
     *
     * @param ConsoleTerminateEvent $event The event
     */
    public function handleConsoleTerminateEvent(ConsoleTerminateEvent $event): void
    {
        metrics()->flush();

        if ($this->isCommandExcluded($event->getCommand())) {
            return;
        }

        $span = $this->hub->getSpan();

        if (null !== $span) {
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
