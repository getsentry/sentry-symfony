<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\EventListener;

use Sentry\DataCollection\DataCollectionPolicy;
use Sentry\SentryBundle\Sentry;
use Sentry\State\IsolationScope;
use Sentry\UserDataBag;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * This listener ensures that a new {@see IsolationScope} is created for
 * each request and that it is filled with useful information, e.g. the IP
 * address of the client.
 */
final class RequestListener
{
    use KernelEventForwardCompatibilityTrait;

    /**
     * @var Sentry
     */
    private $sentry;

    /**
     * Constructor.
     *
     * @param Sentry $sentry
     */
    public function __construct(Sentry $sentry/* , ?TokenStorageInterface $tokenStorage */)
    {
        $this->sentry = $sentry;
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

        if (!DataCollectionPolicy::fromOptions($this->sentry->getClient()->getOptions())->shouldCollectUserInfo()) {
            return;
        }

        $this->sentry->configureScope(static function (IsolationScope $scope) use ($event): void {
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

        $this->sentry->configureScope(static function (IsolationScope $scope) use ($route): void {
            $scope->setTag('route', $route);
        });
    }
}
