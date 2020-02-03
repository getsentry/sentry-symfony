<?php

namespace Sentry\SentryBundle\EventListener;

use Sentry\SentrySdk;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\FilterControllerEvent;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

if (Kernel::MAJOR_VERSION >= 5) {
    class_alias(RequestEvent::class, RequestListenerRequestEvent::class);
    class_alias(ControllerEvent::class, RequestListenerControllerEvent::class);
} else {
    class_alias(GetResponseEvent::class, RequestListenerRequestEvent::class);
    class_alias(FilterControllerEvent::class, RequestListenerControllerEvent::class);
}

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
     * @param RequestListenerRequestEvent $event
     */
    public function onKernelRequest(RequestListenerRequestEvent $event): void
    {
        if (! $event->isMasterRequest()) {
            return;
        }

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

        $userData['ip_address'] = $event->getRequest()->getClientIp();

        SentrySdk::getCurrentHub()
            ->configureScope(function (Scope $scope) use ($userData): void {
                $scope->setUser($userData, true);
            });
    }

    public function onKernelController(RequestListenerControllerEvent $event): void
    {
        if (! $event->isMasterRequest()) {
            return;
        }

        if (! $event->getRequest()->attributes->has('_route')) {
            return;
        }

        $matchedRoute = (string) $event->getRequest()->attributes->get('_route');

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
