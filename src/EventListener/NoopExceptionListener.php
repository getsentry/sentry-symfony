<?php

namespace Sentry\SentryBundle\EventListener;

use Symfony\Component\Console\Event\ConsoleExceptionEvent;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;

/**
 * This class is used to disable sentry exception catching (uncaught exceptions can still be captured via fatal errors)
 * Class NoopExceptionListener
 * @package Sentry\SentryBundle\EventListener
 */
class NoopExceptionListener extends ExceptionListener
{
    /**
     * When an exception occurs as part of a web request, this method will be
     * triggered for capturing the error.
     *
     * @param GetResponseForExceptionEvent $event
     */
    public function onKernelException(GetResponseForExceptionEvent $event): void
    {
        // Do nothing
    }

    /**
     * When an exception occurs on the command line, this method will be
     * triggered for capturing the error.
     *
     * @param ConsoleExceptionEvent $event
     * @deprecated This method exists for BC with Symfony 3.x
     */
    public function onConsoleException(ConsoleExceptionEvent $event): void
    {
        // Do nothing
    }
}