<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\EventListener;

use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Sentry\UserDataBag;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Event\AuthenticationSuccessEvent;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

final class LoginListener
{
    use KernelEventForwardCompatibilityTrait;

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
     * @param HubInterface               $hub          The current hub
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
     */
    public function handleKernelRequestEvent(RequestEvent $event): void
    {
        if (null === $this->tokenStorage || !$this->isMainRequest($event)) {
            return;
        }

        $token = $this->tokenStorage->getToken();

        if (null !== $token) {
            $this->updateUserContext($token);
        }
    }

    /**
     * This method is called after authentication was fully successful. It allows
     * to set information like the username of the currently authenticated user
     * and of the impersonator, if any, on the Sentry's context.
     */
    public function handleLoginSuccessEvent(LoginSuccessEvent $event): void
    {
        $this->updateUserContext($event->getAuthenticatedToken());
    }

    /**
     * This method is called when an authentication provider authenticates the
     * user. It is the event closest to {@see LoginSuccessEvent} in versions of
     * the framework where it doesn't exist.
     */
    public function handleAuthenticationSuccessEvent(AuthenticationSuccessEvent $event): void
    {
        $this->updateUserContext($event->getAuthenticationToken());
    }

    private function updateUserContext(TokenInterface $token): void
    {
        if (!$this->isTokenAuthenticated($token)) {
            return;
        }

        $client = $this->hub->getClient();

        if (null === $client || !$client->getOptions()->shouldSendDefaultPii()) {
            return;
        }

        $this->hub->configureScope(function (Scope $scope) use ($token): void {
            $user = $scope->getUser() ?? new UserDataBag();

            if (null === $user->getId()) {
                $user->setId($this->getUserIdentifier($token->getUser()));
            }

            $impersonatorUser = $this->getImpersonatorUser($token);

            if (null !== $impersonatorUser) {
                $user->setMetadata('impersonator_username', $impersonatorUser);
            }

            $scope->setUser($user);
        });
    }

    private function isTokenAuthenticated(TokenInterface $token): bool
    {
        if (method_exists($token, 'isAuthenticated') && !$token->isAuthenticated(false)) {
            return false;
        }

        return null !== $token->getUser();
    }

    /**
     * @param UserInterface|\Stringable|string|null $user
     */
    private function getUserIdentifier($user): ?string
    {
        if ($user instanceof UserInterface) {
            if (method_exists($user, 'getUserIdentifier')) {
                return $user->getUserIdentifier();
            }

            if (method_exists($user, 'getUsername')) {
                return $user->getUsername();
            }
        }

        if (\is_string($user)) {
            return $user;
        }

        if (\is_object($user) && method_exists($user, '__toString')) {
            return (string) $user;
        }

        return null;
    }

    private function getImpersonatorUser(TokenInterface $token): ?string
    {
        if ($token instanceof SwitchUserToken) {
            return $this->getUserIdentifier($token->getOriginalToken()->getUser());
        }

        return null;
    }
}
