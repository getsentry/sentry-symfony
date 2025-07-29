<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\EventListener;

use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Sentry\UserDataBag;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * This listener ensures that a new {@see Scope} is created for
 * each request and that it is filled with useful information, e.g. the IP
 * address of the client.
 */
final class RequestListener
{
    use KernelEventForwardCompatibilityTrait;

    /**
     * @var HubInterface The current hub
     */
    private $hub;

    /**
     * Constructor.
     *
     * @param HubInterface $hub The current hub
     */
    public function __construct(HubInterface $hub/* , ?TokenStorageInterface $tokenStorage */)
    {
        $this->hub = $hub;
    }

    /**
     * This method is called for each request handled by the framework and
     * fills the Sentry scope with information about the current user.
     *
     * @param RequestEvent $event The event
     */
    public function handleKernelRequestEvent(RequestEvent $event): void
    {
        if (!$this->isMainRequest($event)) {
            return;
        }

        $client = $this->hub->getClient();

        if (null === $client || !$client->getOptions()->shouldSendDefaultPii()) {
            return;
        }

        $this->hub->configureScope(static function (Scope $scope) use ($event): void {
            $user = $scope->getUser() ?? new UserDataBag();

            if (null === $user->getIpAddress()) {
                try {
                    $user->setIpAddress($event->getRequest()->getClientIp());
                } catch (\InvalidArgumentException $e) {
                    // If the IP is in an invalid format, we ignore it
                }
            }

            $scope->setUser($user);
        });
    }

    /**
     * This method is called for each request handled by the framework and
     * sets the route on the current Sentry scope.
     *
     * @param ControllerEvent $event The event
     */
    public function handleKernelControllerEvent(ControllerEvent $event): void
    {
        if (!$this->isMainRequest($event)) {
            return;
        }

        $route = $event->getRequest()->attributes->get('_route');

        if (!\is_string($route)) {
            return;
        }

        $this->hub->configureScope(static function (Scope $scope) use ($route): void {
            $scope->setTag('route', $route);
        });
    }
}
