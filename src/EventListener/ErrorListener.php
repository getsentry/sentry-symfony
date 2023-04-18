<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\EventListener;

use Sentry\Event;
use Sentry\EventHint;
use Sentry\ExceptionMechanism;
use Sentry\State\HubInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

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
     * @param ExceptionEvent $event The event
     */
    public function handleExceptionEvent(ExceptionEvent $event): void
    {
        $hint = EventHint::fromArray([
            'exception' => $event->getThrowable(),
            'mechanism' => new ExceptionMechanism(ExceptionMechanism::TYPE_GENERIC, false),
        ]);

        $this->hub->captureEvent(Event::createEvent(), $hint);
    }
}
