<?php

namespace Sentry\SentryBundle\EventListener;

use Sentry\SentryBundle\Event\SentryUserContextEvent;
use Sentry\SentryBundle\SentrySymfonyEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleEvent;
use Symfony\Component\Console\Event\ConsoleExceptionEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\AuthenticatedVoter;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Class ExceptionListener
 * @package Sentry\SentryBundle\EventListener
 */
class ExceptionListener implements SentryExceptionListenerInterface
{
    /** @var TokenStorageInterface|null */
    private $tokenStorage;

    /** @var AuthorizationCheckerInterface|null */
    private $authorizationChecker;

    /** @var \Raven_Client */
    protected $client;

    /** @var EventDispatcherInterface */
    protected $eventDispatcher;

    /** @var RequestStack */
    private $requestStack;

    /** @var string[] */
    protected $skipCapture;

    /**
     * ExceptionListener constructor.
     * @param \Raven_Client $client
     * @param EventDispatcherInterface $dispatcher
     * @param array $skipCapture
     * @param TokenStorageInterface|null $tokenStorage
     * @param AuthorizationCheckerInterface|null $authorizationChecker
     */
    public function __construct(
        \Raven_Client $client,
        EventDispatcherInterface $dispatcher,
        RequestStack $requestStack,
        array $skipCapture,
        TokenStorageInterface $tokenStorage = null,
        AuthorizationCheckerInterface $authorizationChecker = null
    ) {
        $this->client = $client;
        $this->eventDispatcher = $dispatcher;
        $this->requestStack = $requestStack;
        $this->skipCapture = $skipCapture;
        $this->tokenStorage = $tokenStorage;
        $this->authorizationChecker = $authorizationChecker;
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
    public function onKernelRequest(GetResponseEvent $event): void
    {
        if (HttpKernelInterface::MASTER_REQUEST !== $event->getRequestType()) {
            return;
        }

        if (null === $this->tokenStorage || null === $this->authorizationChecker) {
            return;
        }

        $token = $this->tokenStorage->getToken();

        if (null !== $token && $token->isAuthenticated() && $this->authorizationChecker->isGranted(AuthenticatedVoter::IS_AUTHENTICATED_REMEMBERED)) {
            $this->setUserValue($token->getUser());

            $contextEvent = new SentryUserContextEvent($token);
            $this->eventDispatcher->dispatch(SentrySymfonyEvents::SET_USER_CONTEXT, $contextEvent);
        }
    }

    /**
     * @param GetResponseForExceptionEvent $event
     */
    public function onKernelException(GetResponseForExceptionEvent $event): void
    {
        $exception = $event->getException();

        if ($this->shouldExceptionCaptureBeSkipped($exception)) {
            return;
        }

        $data = ['tags' => []];
        $request = $this->requestStack->getCurrentRequest();
        if ($request instanceof Request) {
            $data['tags']['route'] = $request->attributes->get('_route');
        }

        $this->eventDispatcher->dispatch(SentrySymfonyEvents::PRE_CAPTURE, $event);
        $this->client->captureException($exception, $data);
    }

    /**
     * This method only ensures that the client and error handlers are registered at the start of the command
     * execution cycle, and not only on exceptions
     *
     * @param ConsoleCommandEvent $event
     *
     * @return void
     */
    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        // only triggers loading of client, does not need to do anything.
    }

    public function onConsoleError(ConsoleErrorEvent $event): void
    {
        $this->handleConsoleError($event);
    }

    public function onConsoleException(ConsoleExceptionEvent $event): void
    {
        $this->handleConsoleError($event);
    }

    /**
     * @param ConsoleExceptionEvent|ConsoleErrorEvent $event
     */
    private function handleConsoleError(ConsoleEvent $event): void
    {
        $command = $event->getCommand();
        switch (true) {
            case $event instanceof ConsoleErrorEvent:
                $exception = $event->getError();
                break;
            case $event instanceof ConsoleExceptionEvent:
                $exception = $event->getException();
                break;
            default:
                throw new \InvalidArgumentException('Event not recognized: ' . \get_class($event));
        }

        if ($this->shouldExceptionCaptureBeSkipped($exception)) {
            return;
        }

        $data = [
            'tags' => [
                'command' => $command ? $command->getName() : 'N/A',
                'status_code' => $event->getExitCode(),
            ],
        ];

        $this->eventDispatcher->dispatch(SentrySymfonyEvents::PRE_CAPTURE, $event);
        $this->client->captureException($exception, $data);
    }

    protected function shouldExceptionCaptureBeSkipped(\Throwable $exception): bool
    {
        foreach ($this->skipCapture as $className) {
            if ($exception instanceof $className) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param UserInterface | object | string $user
     */
    private function setUserValue($user)
    {
        $data = [];

        $request = $this->requestStack->getCurrentRequest();
        if ($request instanceof Request) {
            $data['ip_address'] = $request->getClientIp();
        }

        if ($user instanceof UserInterface) {
            $this->client->set_user_data($user->getUsername(), null, $data);

            return;
        }

        if (is_string($user)) {
            $this->client->set_user_data($user, null, $data);

            return;
        }

        if (is_object($user) && method_exists($user, '__toString')) {
            $this->client->set_user_data((string)$user, null, $data);
        }
    }
}
