<?php

namespace Sentry\SentryBundle\EventListener;

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
    if (! class_exists('Sentry\SentryBundle\EventListener\UserContextRequestEvent')) {
        class_alias(RequestEvent::class, 'Sentry\SentryBundle\EventListener\UserContextRequestEvent');
    }
    if (! class_exists('Sentry\SentryBundle\EventListener\UserContextControllerEvent')) {
        class_alias(ControllerEvent::class, 'Sentry\SentryBundle\EventListener\UserContextControllerEvent');
    }
} else {
    if (! class_exists('Sentry\SentryBundle\EventListener\UserContextRequestEvent')) {
        class_alias(GetResponseEvent::class, 'Sentry\SentryBundle\EventListener\UserContextRequestEvent');
    }
    if (! class_exists('Sentry\SentryBundle\EventListener\UserContextControllerEvent')) {
        class_alias(FilterControllerEvent::class, 'Sentry\SentryBundle\EventListener\UserContextControllerEvent');
    }
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
        $this->hub = $hub;
        $this->tokenStorage = $tokenStorage;
    }

    /**
     * Set the username from the security context by listening on core.request
     *
     * @param UserContextRequestEvent $event
     */
    public function onKernelRequest(UserContextRequestEvent $event): void
    {
        if (! $event->isMasterRequest()) {
            return;
        }

        $currentClient = $this->hub->getClient();
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

        $this->hub
            ->configureScope(function (Scope $scope) use ($userData): void {
                $scope->setUser($userData, true);
            });
    }

    public function onKernelController(UserContextControllerEvent $event): void
    {
        if (! $event->isMasterRequest()) {
            return;
        }

        if (! $event->getRequest()->attributes->has('_route')) {
            return;
        }

        $matchedRoute = (string) $event->getRequest()->attributes->get('_route');

        $this->hub
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
