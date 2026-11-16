<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\EventListener;

use Sentry\DataCollection\DataCollectionPolicy;
use Sentry\Event;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Sentry\UserDataBag;
use Symfony\Component\HttpFoundation\RequestStack;
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
     * @var RequestStack|null The request stack
     */
    private $requestStack;

    /**
     * @var bool Whether the event processor adding the user was added to the scope
     */
    private $eventProcessorAdded = false;

    /**
     * Constructor.
     *
     * @param HubInterface               $hub          The current hub
     * @param TokenStorageInterface|null $tokenStorage The token storage
     * @param RequestStack|null          $requestStack The request stack
     */
    public function __construct(HubInterface $hub, ?TokenStorageInterface $tokenStorage, ?RequestStack $requestStack = null)
    {
        $this->hub = $hub;
        $this->tokenStorage = $tokenStorage;
        $this->requestStack = $requestStack;
    }

    /**
     * This method is called for each request handled by the framework. It makes
     * the current user be added to the events sent to Sentry, reading the token
     * only once an event is captured: with a lazy firewall, reading it reads the
     * session, and locks it for the rest of the request with most handlers.
     */
    public function handleKernelRequestEvent(RequestEvent $event): void
    {
        if (null === $this->tokenStorage || $this->eventProcessorAdded || !$this->isMainRequest($event)) {
            return;
        }

        $this->eventProcessorAdded = true;

        $this->hub->configureScope(function (Scope $scope): void {
            $scope->addEventProcessor(function (Event $event): Event {
                return $this->addUserToEvent($event);
            });
        });
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

    private function addUserToEvent(Event $event): Event
    {
        $user = $event->getUser();

        if (null === $this->tokenStorage || (null !== $user && null !== $user->getId())) {
            return $event;
        }

        $request = null !== $this->requestStack ? $this->requestStack->getMainRequest() : null;

        if (null !== $request && $request->attributes->get('_stateless')) {
            return $event;
        }

        if (!DataCollectionPolicy::fromHub($this->hub)->shouldCollectUserInfo()) {
            return $event;
        }

        $token = $this->tokenStorage->getToken();

        if (null === $token || !$this->isTokenAuthenticated($token)) {
            return $event;
        }

        // the user of the event can be the one of the scope, which outlives the request
        $user = null !== $user ? clone $user : new UserDataBag();
        $user->setId($this->getUserIdentifier($token->getUser()));

        $impersonatorUser = $this->getImpersonatorUser($token);

        if (null !== $impersonatorUser) {
            $user->setMetadata('impersonator_username', $impersonatorUser);
        }

        $event->setUser($user);

        return $event;
    }

    private function updateUserContext(TokenInterface $token): void
    {
        if (!$this->isTokenAuthenticated($token)) {
            return;
        }

        if (!DataCollectionPolicy::fromHub($this->hub)->shouldCollectUserInfo()) {
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
