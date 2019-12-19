<?php

namespace Sentry\SentryBundle\EventListener;

use Sentry\State\HubInterface;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
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

    public function onException(ExceptionEvent $event): void
    {
        \Sentry\captureException($event->getThrowable());
    }

    /**
     * BC layer for Symfony < 4.3
     */
    public function onKernelException(GetResponseForExceptionEvent $event): void
    {
        \Sentry\captureException($event->getException());
    }

    public function onConsoleError(ConsoleErrorEvent $event): void
    {
        \Sentry\captureException($event->getError());
    }
}
