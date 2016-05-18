<?php

namespace Sentry\SentryBundle\EventListener;

use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Class ExceptionListener
 * @package Sentry\SentryBundle\EventListener
 */
class ExceptionListener
{
    /** @var  TokenStorageInterface */
    private $tokenStorage;

    /** @var  AuthorizationCheckerInterface */
    private $authorizationChecker;

    /** @var \Raven_Client */
    private $client;

    /**
     * ExceptionListener constructor.
     * @param TokenStorageInterface $tokenStorage
     * @param AuthorizationCheckerInterface $authorizationChecker
     * @param \Raven_Client $client
     */
    public function __construct(
        TokenStorageInterface $tokenStorage,
        AuthorizationCheckerInterface $authorizationChecker,
        \Raven_Client $client = null
    ) {
        if (!$client) {
            $client = new \Raven_Client();
        }

        $this->tokenStorage = $tokenStorage;
        $this->authorizationChecker = $authorizationChecker;
        $this->client = $client;
    }

    /**
     * @param \Raven_Client $client
     */
    public function setClient(\Raven_Client $client)
    {
        $this->client = $client;
    }

    /**
     * Set the username from the security context by listening on core.request
     *
     * @param GetResponseEvent $event
     */
    public function onKernelRequest(GetResponseEvent $event)
    {
        if (HttpKernelInterface::MASTER_REQUEST !== $event->getRequestType()) {
            return;
        }

        if (null === $this->tokenStorage || null === $this->authorizationChecker) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        if (null !== $token && $this->authorizationChecker->isGranted('IS_AUTHENTICATED_REMEMBERED')) {
            $this->setUserValue($token->getUser());
        }
    }
    
    /**
     * @param GetResponseForExceptionEvent $event
     */
    public function onKernelException(GetResponseForExceptionEvent $event)
    {
        $exception = $event->getException();

        // don't capture HTTP responses
        if ($exception instanceof HttpExceptionInterface) {
            return;
        }

        $this->client->captureException($exception);
    }

    /**
     * @param UserInterface | object | string $user
     */
    private function setUserValue($user)
    {
        switch (true) {
            case $user instanceof UserInterface:
                $this->client->set_user_data($user->getUsername());
                return;
            case is_object($user):
            case is_string($user):
                $this->client->set_user_data((string) $user);
                return;
        }
    }
}
