<?php

namespace Sentry\SentryBundle\EventListener;

use Sentry\Logs\Logs;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

/**
 * RequestListener for sentry log related events.
 */
class LogRequestListener
{

    /**
     * Flushes the logs on kernel termination.
     *
     * @param TerminateEvent $event
     * @return void
     */
    public function handleKernelTerminateEvent(TerminateEvent $event)
    {
        Logs::getInstance()->flush();
    }

}
