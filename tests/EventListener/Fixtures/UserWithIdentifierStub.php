<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\EventListener\Fixtures;

use Symfony\Component\Security\Core\User\UserInterface;

final class UserWithIdentifierStub implements UserInterface
{
    /**
     * @var string
     */
    private $username;

    public function __construct(string $username = 'foo_user')
    {
        $this->username = $username;
    }

    public function getUserIdentifier(): string
    {
        return $this->getUsername();
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getRoles(): array
    {
        return [];
    }

    public function getPassword(): ?string
    {
        return null;
    }

    public function getSalt(): ?string
    {
        return null;
    }

    public function eraseCredentials(): void
    {
    }
}
