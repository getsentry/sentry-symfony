<?php

namespace Sentry\SentryBundle\EventListener;

use Sentry\SentrySdk;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Kernel;

if (Kernel::MAJOR_VERSION >= 5) {
    class_alias(RequestEvent::class, SubRequestListenerRequestEvent::class);
} else {
    class_alias(GetResponseEvent::class, SubRequestListenerRequestEvent::class);
}

final class SubRequestListener
{
    /**
     * Pushes a new {@see Scope} for each SubRequest
     *
     * @param SubRequestListenerRequestEvent $event
     */
    public function onKernelRequest(SubRequestListenerRequestEvent $event): void
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
