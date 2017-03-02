<?php

namespace Sentry\SentryBundle\Event;

use Symfony\Component\EventDispatcher\Event;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * The SentryUserContextEvent.
 *
 * @author Sean Quinn <swquinn@gmail.com>
 * @package Sentry\SentryBundle\Event
 */
class SentryUserContextEvent extends Event implements SentryContextEventInterface
{
    private $authenticationToken;

    /**
     * Constructor.
     *
     * @param TokenInterface $authenticationToken A TokenInterface instance
     */
    public function __construct(TokenInterface $authenticationToken)
    {
        $this->authenticationToken = $authenticationToken;
    }

    /**
     * Gets the authentication token.
     *
     * @return TokenInterface A TokenInterface instance
     */
    public function getAuthenticationToken()
    {
        return $this->authenticationToken;
    }
}
