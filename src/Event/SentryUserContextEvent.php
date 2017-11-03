<?php

namespace Sentry\SentryBundle\Event;

use Symfony\Component\EventDispatcher\Event;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

class SentryUserContextEvent extends Event
{
    /** @var TokenInterface */
    private $authenticationToken;

    public function __construct(TokenInterface $authenticationToken)
    {
        $this->authenticationToken = $authenticationToken;
    }

    public function getAuthenticationToken(): TokenInterface
    {
        return $this->authenticationToken;
    }
}
