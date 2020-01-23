<?php

namespace Sentry\SentryBundle\EventListener;

use Sentry\SentrySdk;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\FilterControllerEvent;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Class RequestListener
 * @package Sentry\SentryBundle\EventListener
 */
final class RequestListener
{
    /** @var HubInterface */
    private $hub;

    /** @var TokenStorageInterface|null */
    private $tokenStorage;

    /**
     * RequestListener constructor.
     * @param HubInterface $hub
     * @param TokenStorageInterface|null $tokenStorage
     */
    public function __construct(
        HubInterface $hub,
        ?TokenStorageInterface $tokenStorage
    ) {
        $this->hub = $hub; // not used, needed to trigger instantiation
        $this->tokenStorage = $tokenStorage;
    }

    /**
     * Set the username from the security context by listening on core.request
     *
     * @param RequestEvent $event
     */
    public function onRequest(RequestEvent $event): void
    {
        if (! $event->isMasterRequest()) {
            return;
        }

        $this->handleRequestEvent($event->getRequest());
    }

    /**
     * BC layer for SF < 4.3
     */
    public function onKernelRequest(GetResponseEvent $event): void
    {
        if (! $event->isMasterRequest()) {
            return;
        }

        $this->handleRequestEvent($event->getRequest());
    }

    private function handleRequestEvent(Request $request): void
    {
        $currentClient = SentrySdk::getCurrentHub()->getClient();
        if (null === $currentClient || ! $currentClient->getOptions()->shouldSendDefaultPii()) {
            return;
        }

        $token = null;

        if ($this->tokenStorage instanceof TokenStorageInterface) {
            $token = $this->tokenStorage->getToken();
        }

        $userData = [];

        if (
            null !== $token
            && $token->isAuthenticated()
            && $token->getUser()
        ) {
            $userData = $this->getUserData($token->getUser());
        }

        $userData['ip_address'] = $request->getClientIp();

        SentrySdk::getCurrentHub()
            ->configureScope(function (Scope $scope) use ($userData): void {
                $scope->setUser($userData, true);
            });
    }

    public function onController(ControllerEvent $event): void
    {
        if (! $event->isMasterRequest()) {
            return;
        }

        $this->handleControllerEvent($event->getRequest());
    }

    /**
     * BC layer for SF < 4.3
     */
    public function onKernelController(FilterControllerEvent $event): void
    {
        if (! $event->isMasterRequest()) {
            return;
        }

        $this->handleControllerEvent($event->getRequest());
    }

    private function handleControllerEvent(Request $request): void
    {
        if (! $request->attributes->has('_route')) {
            return;
        }

        $matchedRoute = (string)$request->attributes->get('_route');

        SentrySdk::getCurrentHub()
            ->configureScope(function (Scope $scope) use ($matchedRoute): void {
                $scope->setTag('route', $matchedRoute);
            });
    }

    /**
     * @param UserInterface | object | string $user
     */
    private function getUserData($user): array
    {
        if ($user instanceof UserInterface) {
            return [
                'username' => $user->getUsername(),
            ];
        }

        if (is_string($user)) {
            return [
                'username' => $user,
            ];
        }

        if (is_object($user) && method_exists($user, '__toString')) {
            return [
                'username' => $user->__toString(),
            ];
        }

        return [];
    }
}
