<?php

namespace Sentry\SentryBundle\EventListener;

use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleExceptionEvent;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;

final class ErrorListener
{
    public function onKernelException(GetResponseForExceptionEvent $event): void
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
