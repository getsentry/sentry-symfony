<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\EventListener;

use Sentry\SentrySdk;
use Sentry\State\HubInterface;
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
     * Keep HubInterface as an explicit dependency so the container initializes
     * and configures the hub service before this listener handles requests.
     */
    public function __construct(HubInterface $hub)
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
