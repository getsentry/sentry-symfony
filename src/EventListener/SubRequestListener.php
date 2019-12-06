<?php

namespace Sentry\SentryBundle\EventListener;

use Sentry\SentryBundle\SentryBundle;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

if (! class_exists(ResponseEvent::class)) {
    class_alias(ResponseEvent::class, GetResponseEvent::class);
}

final class SubRequestListener
{
    /**
     * Pushes a new {@see Scope} for each SubRequest
     *
     * @param ResponseEvent $event
     */
    public function onKernelRequest(ResponseEvent $event): void
    {
        if ($event->isMasterRequest()) {
            return;
        }

        SentryBundle::getCurrentHub()->pushScope();
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

        SentryBundle::getCurrentHub()->popScope();
    }
}
