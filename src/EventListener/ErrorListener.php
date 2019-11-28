<?php

namespace Sentry\SentryBundle\EventListener;

use Sentry\State\HubInterface;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleExceptionEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;

final class ErrorListener
{
    /** @var HubInterface */
    private $hub;

    /**
     * ErrorListener constructor.
     * @param HubInterface $hub
     */
    public function __construct(HubInterface $hub)
    {
        $this->hub = $hub; // not used, needed to trigger instantiation
    }

    public function onKernelException(ErrorListenerExceptionEvent $event): void
    {
        \Sentry\captureException($event->getException());
    }

    public function onConsoleError(ConsoleErrorEvent $event): void
    {
        \Sentry\captureException($event->getError());
    }

    /**
     * BC layer for Symfony < 3.3; see https://symfony.com/blog/new-in-symfony-3-3-better-handling-of-command-exceptions
     */
    public function onConsoleException(ConsoleExceptionEvent $event): void
    {
        \Sentry\captureException($event->getException());
    }
}

if (\class_exists(ExceptionEvent::class)) {
    \class_alias(ExceptionEvent::class, ErrorListenerExceptionEvent::class);
} else {
    \class_alias(GetResponseForExceptionEvent::class, ErrorListenerExceptionEvent::class);
}
