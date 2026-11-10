<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\EventListener;

use Sentry\SentryBundle\Sentry;
use Sentry\SentrySdk;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Starts and ends an isolated runtime context for each main HTTP request.
 *
 * This prevents scope, logs and metrics data from leaking across requests when
 * running on persistent workers such as FrankenPHP or RoadRunner.
 */
final class RuntimeContextListener implements ResetInterface
{
    use KernelEventForwardCompatibilityTrait;

    /**
     * Keep Sentry as an explicit dependency so the container initializes
     * it and binds the client before this listener handles requests.
     */
    public function __construct(Sentry $sentry)
    {
    }

    public function handleKernelRequestEvent(RequestEvent $event): void
    {
        if (!$this->isMainRequest($event)) {
            return;
        }

        SentrySdk::startContext();
    }

    public function handleKernelTerminateEvent(TerminateEvent $event): void
    {
        if (!$this->isMainRequest($event)) {
            return;
        }

        SentrySdk::endContext();
    }

    public function reset(): void
    {
        SentrySdk::endContext();
    }
}
