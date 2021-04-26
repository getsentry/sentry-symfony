<?php

namespace Sentry\SentryBundle\EventListener;

use Sentry\SentrySdk;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Kernel;

if (Kernel::MAJOR_VERSION >= 5) {
    if (! class_exists(SubRequestListenerRequestEvent::class, false)) {
        class_alias(RequestEvent::class, SubRequestListenerRequestEvent::class);
    }
} else {
    if (! class_exists(SubRequestListenerRequestEvent::class, false)) {
        class_alias(GetResponseEvent::class, SubRequestListenerRequestEvent::class);
    }
}

final class SubRequestListener
{
    use KernelEventForwardCompatibilityTrait;

    /**
     * Pushes a new {@see Scope} for each SubRequest
     *
     * @param SubRequestListenerRequestEvent $event
     */
    public function onKernelRequest(SubRequestListenerRequestEvent $event): void
    {
        if ($this->isMainRequest($event)) {
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
        if ($this->isMainRequest($event)) {
            return;
        }

        SentrySdk::getCurrentHub()->popScope();
    }
}
