<?php

namespace Sentry\SentryBundle\EventListener;

use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Sentry\UserDataBag;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * This listener ensures that a new {@see \Sentry\State\Scope} is created for
 * each request and that it is filled with useful information, e.g. the IP
 * address of the client.
 */
final class RequestListener
{
    /**
     * @var HubInterface The current hub
     */
    private $hub;

    /**
     * @var TokenStorageInterface|null The token storage
     */
    private $tokenStorage;

    /**
     * Constructor.
     *
     * @param HubInterface $hub The current hub
     * @param TokenStorageInterface|null $tokenStorage The token storage
     */
    public function __construct(HubInterface $hub, ?TokenStorageInterface $tokenStorage)
    {
        $this->hub = $hub;
        $this->tokenStorage = $tokenStorage;
    }

    /**
     * This method is called for each request handled by the framework and
     * fills the Sentry scope with information about the current user.
     *
     * @param RequestListenerRequestEvent $event The event
     */
    public function handleKernelRequestEvent(RequestListenerRequestEvent $event): void
    {
        if (! $event->isMasterRequest()) {
            return;
        }

        $token = null;
        $userData = UserDataBag::createFromUserIpAddress($event->getRequest()->getClientIp());

        if (null !== $this->tokenStorage) {
            $token = $this->tokenStorage->getToken();
        }

        if (null !== $token && $token->isAuthenticated() && $token->getUser()) {
            $userData->setUsername($this->getUsername($token->getUser()));
        }

        $this->hub->configureScope(static function (Scope $scope) use ($userData): void {
            $scope->setUser($userData);
        });
    }

    /**
     * This method is called for each request handled by the framework and
     * sets the route on the current Sentry scope.
     *
     * @param RequestListenerControllerEvent $event The event
     */
    public function handleKernelControllerEvent(RequestListenerControllerEvent $event): void
    {
        if (! $event->isMasterRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (! $request->attributes->has('_route')) {
            return;
        }

        $this->hub->configureScope(static function (Scope $scope) use ($request): void {
            $scope->setTag('route', $request->attributes->get('_route'));
        });
    }

    /**
     * @param UserInterface | object | string $user
     */
    private function getUsername($user): ?string
    {
        if ($user instanceof UserInterface) {
            return $user->getUsername();
        }

        if (is_string($user)) {
            return $user;
        }

        if (is_object($user) && method_exists($user, '__toString')) {
            return (string) $user;
        }

        return null;
    }
}
