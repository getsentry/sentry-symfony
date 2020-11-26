<?php

namespace Sentry\SentryBundle\EventListener;

use Symfony\Component\HttpKernel\Kernel;
use Sentry\State\HubInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;

if (version_compare(Kernel::VERSION, '4.3.0', '>=')) {
    if (! class_exists(ErrorListenerExceptionEvent::class, false)) {
        class_alias(ExceptionEvent::class, ErrorListenerExceptionEvent::class);
    }
} else {
    if (! class_exists(ErrorListenerExceptionEvent::class, false)) {
        class_alias(GetResponseForExceptionEvent::class, ErrorListenerExceptionEvent::class);
    }
}

/**
 * This listener listens for error events and logs them to Sentry.
 */
final class ErrorListener
{
    /**
     * @var HubInterface The current hub
     */
    private $hub;

    /**
     * Constructor.
     *
     * @param HubInterface $hub The current hub
     */
    public function __construct(HubInterface $hub)
    {
        $this->hub = $hub;
    }

    /**
     * Handles an exception that happened while running the application.
     *
     * @param ErrorListenerExceptionEvent $event The event
     */
    public function handleExceptionEvent(ErrorListenerExceptionEvent $event): void
    {
        if ($event instanceof ExceptionEvent) {
            $this->hub->captureException($event->getThrowable());
        } else {
            $this->hub->captureException($event->getException());
        }
    }
}
