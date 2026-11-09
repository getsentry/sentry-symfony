<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\EventListener;

use Sentry\Event;
use Sentry\EventHint;
use Sentry\ExceptionMechanism;
use Sentry\SentryBundle\SentryManager;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

/**
 * This listener listens for error events and logs them to Sentry.
 */
final class ErrorListener
{
    /**
     * @var SentryManager
     */
    private $sentry;

    /**
     * Constructor.
     *
     * @param SentryManager $sentry
     */
    public function __construct(SentryManager $sentry)
    {
        $this->sentry = $sentry;
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

        $this->sentry->captureEvent(Event::createEvent(), $hint);
    }
}
