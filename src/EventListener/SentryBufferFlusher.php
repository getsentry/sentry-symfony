<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\EventListener;

use Monolog\Handler\BufferHandler;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class SentryBufferFlusher implements EventSubscriberInterface
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
        return [
            KernelEvents::TERMINATE => ['onKernelTerminate', 10],
            ConsoleEvents::TERMINATE => ['onConsoleTerminate', 10],
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

    private function flushBuffers(): void
    {
        foreach ($this->bufferHandlers as $handler) {
            $handler->flush();
        }
    }
}
