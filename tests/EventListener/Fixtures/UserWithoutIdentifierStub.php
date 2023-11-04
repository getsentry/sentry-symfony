<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\EventListener\Fixtures;

use Symfony\Component\Security\Core\User\UserInterface;

final class UserWithoutIdentifierStub implements UserInterface
{
    public function getUsername(): string
    {
        return 'foo_user';
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
