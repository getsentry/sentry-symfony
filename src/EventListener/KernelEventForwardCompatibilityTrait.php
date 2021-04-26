<?php

namespace Sentry\SentryBundle\EventListener;

use Symfony\Component\HttpKernel\Event\KernelEvent;

/**
 * Provides forward compatibility with newer Symfony versions.
 *
 * @internal
 */
trait KernelEventForwardCompatibilityTrait
{
    private function isMainRequest(KernelEvent $event): bool
    {
        return method_exists($event, 'isMainRequest')
            ? $event->isMainRequest()
            : $event->isMasterRequest()
        ;
    }
}
