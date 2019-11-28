<?php

namespace Sentry\SentryBundle\EventListener;

use Sentry\SentryBundle\SentryBundle;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

final class SubRequestListener
{
    /**
     * Pushes a new {@see Scope} for each SubRequest
     *
     * @param SubRequestListenerResponseEvent $event
     */
    public function onKernelRequest(SubRequestListenerResponseEvent $event): void
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

if (\class_exists(ResponseEvent::class)) {
    \class_alias(ResponseEvent::class, SubRequestListenerResponseEvent::class);
} else {
    \class_alias(GetResponseEvent::class, SubRequestListenerResponseEvent::class);
}
