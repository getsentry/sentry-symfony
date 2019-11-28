<?php

namespace Sentry\SentryBundle\EventListener;

use Sentry\State\HubInterface;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleExceptionEvent;
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

    public function onKernelException(GetResponseForExceptionEvent $event): void
    {
        if (method_exists($event, 'getThrowable')) {
            $throwable = $event->getThrowable();
        } else {
            // Support for Symfony 4.3 and before
            $throwable = $event->getException();
        }

        \Sentry\captureException($throwable);
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
