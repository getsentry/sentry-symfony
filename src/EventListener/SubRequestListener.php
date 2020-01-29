<?php

namespace Sentry\SentryBundle\EventListener;

use Sentry\SentrySdk;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Kernel;

if (Kernel::MAJOR_VERSION >= 5) {
    if (! class_exists('Sentry\SentryBundle\EventListener\UserContextRequestEvent')) {
        class_alias(RequestEvent::class, 'Sentry\SentryBundle\EventListener\UserContextRequestEvent');
    }
} else {
    if (! class_exists('Sentry\SentryBundle\EventListener\UserContextRequestEvent')) {
        class_alias(GetResponseEvent::class, 'Sentry\SentryBundle\EventListener\UserContextRequestEvent');
    }
}

final class SubRequestListener
{
    /**
     * Pushes a new {@see Scope} for each SubRequest
     *
     * @param UserContextRequestEvent $event
     */
    public function onKernelRequest(UserContextRequestEvent $event): void
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
