<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\EventListener;

use Monolog\Handler\BufferHandler;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Class that wraps Sentry monolog handlers to flush them for certain lifecycle events.
 * This is required to emit proper scope based information like tags and breadcrumbs.
 *
 * Without this class, buffered monolog messages are flushed when the request finishes at which
 * point breadcrumbs and tags are no longer present in the scope.
 */
class BufferFlusher implements EventSubscriberInterface
{
    /**
     * @var BufferHandler[]
     */
    private $bufferHandlers;

    /**
     * @param BufferHandler[] $bufferHandlers
     */
    public function __construct(array $bufferHandlers = [])
    {
        $this->bufferHandlers = $bufferHandlers;
    }

    public static function getSubscribedEvents(): array
    {
        // Flush the Monolog buffer before any scope is destroyed so that events
        // get augmented with properly scoped data.
        // For ConsoleEvents::COMMAND, we have to flush before ConsoleListener::handleConsoleCommandEvent(..)
        // runs so that the proper tags get attached to the event.
        // Running with lower priority will make the ConsoleListener run before and create a new scope
        // with the new command name when running a symfony Command within another Command.
        return [
            KernelEvents::TERMINATE => ['onKernelTerminate', 10],
            ConsoleEvents::COMMAND => ['onConsoleCommand', 150],
            ConsoleEvents::TERMINATE => ['onConsoleTerminate', 10],
            ConsoleEvents::ERROR => ['onConsoleError', 10],
        ];
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        $this->flushBuffers();
    }

    public function onConsoleTerminate(ConsoleTerminateEvent $event): void
    {
        $this->flushBuffers();
    }

    public function onConsoleError(ConsoleErrorEvent $event): void
    {
        $this->flushBuffers();
    }

    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        $this->flushBuffers();
    }

    private function flushBuffers(): void
    {
        foreach ($this->bufferHandlers as $handler) {
            $handler->flush();
        }
    }
}
