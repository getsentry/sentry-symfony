<?php

namespace Sentry\SentryBundle\EventListener;

use Sentry\SentrySdk;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;

if (! class_exists(RequestEvent::class)) {
    class_alias(GetResponseEvent::class, RequestEvent::class);
}

final class SubRequestListener
{
    /**
     * Pushes a new {@see Scope} for each SubRequest
     *
     * @param RequestEvent $event
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if ($event->isMasterRequest()) {
            return;
        }

        SentrySdk::getCurrentHub()->pushScope();
    }

    /**
     * Pops a {@see Scope} for each finished SubRequest
     *
     * @param FinishRequestEvent $event
     */
    public function onKernelFinishRequest(FinishRequestEvent $event): void
    {
        if ($event->isMasterRequest()) {
            return;
        }

        SentrySdk::getCurrentHub()->popScope();
    }
}
