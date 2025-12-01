<?php

namespace Sentry\SentryBundle\EventListener;

use Sentry\Metrics\TraceMetrics;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

/**
 * RequestListener for sentry metrics.
 */
class MetricsRequestListener
{
    /**
     * Flushes all metrics on kernel termination.
     *
     * @param TerminateEvent $event
     *
     * @return void
     */
    public function handleKernelTerminateEvent(TerminateEvent $event)
    {
        TraceMetrics::getInstance()->flush();
    }
}
