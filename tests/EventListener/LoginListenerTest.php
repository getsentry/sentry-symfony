<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\EventListener;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\Options;
use Sentry\SentryBundle\EventListener\LoginListener;
use Sentry\SentryBundle\Tests\EventListener\Fixtures\UserWithIdentifierStub;
use Sentry\SentryBundle\Tests\EventListener\Fixtures\UserWithoutIdentifierStub;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Sentry\UserDataBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Security\Core\Authentication\Token\AbstractToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Event\AuthenticationSuccessEvent;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

final class LoginListenerTest extends TestCase
{
    /**
     * @var MockObject&HubInterface
     */
    private $hub;

    /**
     * @var MockObject&TokenStorageInterface
     */
    private $tokenStorage;

    /**
     * @var LoginListener
     */
    private $listener;

    protected function setUp(): void
    {
        $this->hub = $this->createMock(HubInterface::class);
        $this->tokenStorage = $this->createMock(TokenStorageInterface::class);
        $this->listener = new LoginListener($this->hub, $this->tokenStorage);
    }

    /**
     * @dataProvider authenticationTokenDataProvider
     * @dataProvider authenticationTokenForSymfonyVersionLowerThan54DataProvider
     */
    public function testHandleKernelRequestEvent(TokenInterface $token, ?UserDataBag $user, ?UserDataBag $expectedUser): void
    {
        $scope = new Scope();

        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())
            ->method('getOptions')
            ->willReturn(new Options(['send_default_pii' => true]));

        $this->hub->expects($this->once())
            ->method('getClient')
            ->willReturn($client);

        $this->hub->expects($this->once())
            ->method('configureScope')
            ->willReturnCallback(static function (callable $callback) use ($scope): void {
                $callback($scope);
            });

        $this->tokenStorage->expects($this->once())
            ->method('getToken')
            ->willReturn($token);

        if (null !== $user) {
            $scope->setUser($user);
        }

        $this->listener->handleKernelRequestEvent(new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request(),
            \defined(HttpKernelInterface::class . '::MAIN_REQUEST') ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::MASTER_REQUEST
        ));

        $event = $scope->applyToEvent(Event::createEvent());

        $this->assertNotNull($event);
        $this->assertEquals($expectedUser, $event->getUser());
    }

    /**
     * @dataProvider authenticationTokenDataProvider
     */
    public function testHandleLoginSuccessEvent(TokenInterface $token, ?UserDataBag $user, ?UserDataBag $expectedUser): void
    {
        if (!class_exists(LoginSuccessEvent::class)) {
            $this->markTestSkipped('This test is incompatible with versions of Symfony where the LoginSuccessEvent event does not exist.');
        }

        $scope = new Scope();

        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())
            ->method('getOptions')
            ->willReturn(new Options(['send_default_pii' => true]));

        $this->hub->expects($this->once())
            ->method('getClient')
            ->willReturn($client);

        $this->hub->expects($this->once())
            ->method('configureScope')
            ->willReturnCallback(static function (callable $callback) use ($scope): void {
                $callback($scope);
            });

        if (null !== $user) {
            $scope->setUser($user);
        }

        $this->listener->handleLoginSuccessEvent(new LoginSuccessEvent(
            $this->createMock(AuthenticatorInterface::class),
            new SelfValidatingPassport(new UserBadge('foo_passport_user')),
            $token,
            new Request(),
            null,
            'main'
        ));

        $event = $scope->applyToEvent(Event::createEvent());

        $this->assertNotNull($event);
        $this->assertEquals($expectedUser, $event->getUser());
    }

    /**
     * @dataProvider authenticationTokenDataProvider
     * @dataProvider authenticationTokenForSymfonyVersionLowerThan54DataProvider
     */
    public function testHandleAuthenticationSuccessEvent(TokenInterface $token, ?UserDataBag $user, ?UserDataBag $expectedUser): void
    {
        if (class_exists(LoginSuccessEvent::class)) {
            $this->markTestSkipped('This test is incompatible with versions of Symfony where the LoginSuccessEvent event exists.');
        }

        $scope = new Scope();

        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())
            ->method('getOptions')
            ->willReturn(new Options(['send_default_pii' => true]));

        $this->hub->expects($this->once())
            ->method('getClient')
            ->willReturn($client);

        $this->hub->expects($this->once())
            ->method('configureScope')
            ->willReturnCallback(static function (callable $callback) use ($scope): void {
                $callback($scope);
            });

        if (null !== $user) {
            $scope->setUser($user);
        }

        $this->listener->handleAuthenticationSuccessEvent(new AuthenticationSuccessEvent($token));

        $event = $scope->applyToEvent(Event::createEvent());

        $this->assertNotNull($event);
        $this->assertEquals($expectedUser, $event->getUser());
    }

    public function authenticationTokenDataProvider(): \Generator
    {
        if (version_compare(Kernel::VERSION, '5.4', '<')) {
            yield 'If the username is already set on the User context, then it is not overridden' => [
                new LegacyAuthenticatedTokenStub(new UserWithIdentifierStub()),
                new UserDataBag('bar_user'),
                new UserDataBag('bar_user'),
            ];
        } else {
            yield 'If the username is already set on the User context, then it is not overridden' => [
                new AuthenticatedTokenStub(new UserWithIdentifierStub()),
                new UserDataBag('bar_user'),
                new UserDataBag('bar_user'),
            ];
        }

        if (version_compare(Kernel::VERSION, '5.4', '<')) {
            yield 'If the username is not set on the User context, then it is retrieved from the token' => [
                new LegacyAuthenticatedTokenStub(new UserWithIdentifierStub()),
                null,
                new UserDataBag('foo_user'),
            ];
        } else {
            yield 'If the username is not set on the User context, then it is retrieved from the token' => [
                new AuthenticatedTokenStub(new UserWithIdentifierStub()),
                null,
                new UserDataBag('foo_user'),
            ];
        }

        yield 'If the user is being impersonated, then the username of the impersonator is set on the User context' => [
            (static function (): SwitchUserToken {
                if (version_compare(Kernel::VERSION, '5.0.0', '<')) {
                    return new SwitchUserToken(
                        new UserWithIdentifierStub(),
                        null,
                        'foo_provider',
                        ['ROLE_USER'],
                        // @phpstan-ignore-next-line
                        new LegacyAuthenticatedTokenStub(new UserWithIdentifierStub('bar_user'))
                    );
                }

                return new SwitchUserToken(
                    new UserWithIdentifierStub(),
                    'main',
                    ['ROLE_USER'],
                    new AuthenticatedTokenStub(new UserWithIdentifierStub('bar_user'))
                );
            })(),
            null,
            UserDataBag::createFromArray([
                'id' => 'foo_user',
                'impersonator_username' => 'bar_user',
            ]),
        ];
    }

    public function authenticationTokenForSymfonyVersionLowerThan54DataProvider(): \Generator
    {
        if (version_compare(Kernel::VERSION, '5.4.0', '>=')) {
            return;
        }

        if (version_compare(Kernel::VERSION, '5.0', '<')) {
            yield 'If the user is a string, then the value is used as-is' => [
                new LegacyAuthenticatedTokenStub('foo_user'),
                null,
                new UserDataBag('foo_user'),
            ];
        } else {
            yield 'If the user is a string, then the value is used as-is' => [
                new AuthenticatedTokenStub('foo_user'),
                null,
                new UserDataBag('foo_user'),
            ];
        }

        if (version_compare(Kernel::VERSION, '5.0', '<')) {
            yield 'If the user is an instance of the UserInterface interface but the getUserIdentifier() method does not exist, then the getUsername() method is invoked' => [
                new LegacyAuthenticatedTokenStub(new UserWithoutIdentifierStub()),
                null,
                new UserDataBag('foo_user'),
            ];
        } else {
            yield 'If the user is an instance of the UserInterface interface but the getUserIdentifier() method does not exist, then the getUsername() method is invoked' => [
                new AuthenticatedTokenStub(new UserWithoutIdentifierStub()),
                null,
                new UserDataBag('foo_user'),
            ];
        }

        if (version_compare(Kernel::VERSION, '5.0', '<')) {
            yield 'If the user is an object implementing the Stringable interface, then the __toString() method is invoked' => [
                new LegacyAuthenticatedTokenStub(new class implements \Stringable {
                    public function __toString(): string
                    {
                        return 'foo_user';
                    }
                }),
                null,
                new UserDataBag('foo_user'),
            ];
        } else {
            yield 'If the user is an object implementing the Stringable interface, then the __toString() method is invoked' => [
                new AuthenticatedTokenStub(new class implements \Stringable {
                    public function __toString(): string
                    {
                        return 'foo_user';
                    }
                }),
                null,
                new UserDataBag('foo_user'),
            ];
        }
    }

    public function testHandleKernelRequestEventDoesNothingIfRequestIsForStatelessRoute(): void
    {
        $this->tokenStorage->expects($this->never())
            ->method('getToken');

        $this->listener->handleKernelRequestEvent(new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request(attributes: ['_stateless' => true]),
            HttpKernelInterface::SUB_REQUEST
        ));
    }

    public function testHandleKernelRequestEventDoesNothingIfRequestIsNotMain(): void
    {
        $this->tokenStorage->expects($this->never())
            ->method('getToken');

        $this->listener->handleKernelRequestEvent(new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request(),
            HttpKernelInterface::SUB_REQUEST
        ));
    }

    public function testHandleKernelRequestEventDoesNothingIfTokenIsNotSet(): void
    {
        $this->tokenStorage->expects($this->once())
            ->method('getToken')
            ->willReturn(null);

        $this->listener->handleKernelRequestEvent(new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request(),
            \defined(HttpKernelInterface::class . '::MAIN_REQUEST') ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::MASTER_REQUEST
        ));
    }

    public function testHandleLoginSuccessEventDoesNothingIfTokenIsNotAuthenticated(): void
    {
        if (!class_exists(LoginSuccessEvent::class)) {
            $this->markTestSkipped('This test is incompatible with versions of Symfony where the LoginSuccessEvent event does not exist.');
        }

        $this->hub->expects($this->never())
            ->method('getClient');

        $this->hub->expects($this->never())
            ->method('configureScope');

        $this->listener->handleLoginSuccessEvent(new LoginSuccessEvent(
            $this->createMock(AuthenticatorInterface::class),
            new SelfValidatingPassport(new UserBadge('foo_passport_user')),
            new UnauthenticatedTokenStub(),
            new Request(),
            null,
            'main'
        ));
    }

    public function testHandleLoginSuccessEventDoesNothingIfClientIsNotSetOnHub(): void
    {
        if (!class_exists(LoginSuccessEvent::class)) {
            $this->markTestSkipped('This test is incompatible with versions of Symfony where the LoginSuccessEvent event does not exist.');
        }

        $this->hub->expects($this->once())
            ->method('getClient')
            ->willReturn(null);

        $this->hub->expects($this->never())
            ->method('configureScope');

        if (version_compare(Kernel::VERSION, '5.4', '<')) {
            $this->listener->handleLoginSuccessEvent(new LoginSuccessEvent(
                $this->createMock(AuthenticatorInterface::class),
                new SelfValidatingPassport(new UserBadge('foo_passport_user')),
                new LegacyAuthenticatedTokenStub(new UserWithIdentifierStub()),
                new Request(),
                null,
                'main'
            ));
        } else {
            $this->listener->handleLoginSuccessEvent(new LoginSuccessEvent(
                $this->createMock(AuthenticatorInterface::class),
                new SelfValidatingPassport(new UserBadge('foo_passport_user')),
                new AuthenticatedTokenStub(new UserWithIdentifierStub()),
                new Request(),
                null,
                'main'
            ));
        }
    }

    public function testHandleLoginSuccessEventDoesNothingIfSendingDefaultPiiIsDisabled(): void
    {
        if (!class_exists(LoginSuccessEvent::class)) {
            $this->markTestSkipped('This test is incompatible with versions of Symfony where the LoginSuccessEvent event does not exist.');
        }

        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())
            ->method('getOptions')
            ->willReturn(new Options(['send_default_pii' => false]));

        $this->hub->expects($this->once())
            ->method('getClient')
            ->willReturn($client);

        $this->hub->expects($this->never())
            ->method('configureScope');

        if (version_compare(Kernel::VERSION, '5.4', '<')) {
            $this->listener->handleLoginSuccessEvent(new LoginSuccessEvent(
                $this->createMock(AuthenticatorInterface::class),
                new SelfValidatingPassport(new UserBadge('foo_passport_user')),
                new LegacyAuthenticatedTokenStub(new UserWithIdentifierStub()),
                new Request(),
                null,
                'main'
            ));
        } else {
            $this->listener->handleLoginSuccessEvent(new LoginSuccessEvent(
                $this->createMock(AuthenticatorInterface::class),
                new SelfValidatingPassport(new UserBadge('foo_passport_user')),
                new AuthenticatedTokenStub(new UserWithIdentifierStub()),
                new Request(),
                null,
                'main'
            ));
        }
    }

    public function testHandleAuthenticationSuccessEventDoesNothingIfTokenIsNotAuthenticated(): void
    {
        if (class_exists(LoginSuccessEvent::class)) {
            $this->markTestSkipped('This test is incompatible with versions of Symfony where the LoginSuccessEvent event exists.');
        }

        $this->hub->expects($this->never())
            ->method('getClient');

        $this->hub->expects($this->never())
            ->method('configureScope');

        $this->listener->handleAuthenticationSuccessEvent(new AuthenticationSuccessEvent(new UnauthenticatedTokenStub()));
    }

    public function testHandleAuthenticationSuccessEventDoesNothingIfClientIsNotSetOnHub(): void
    {
        if (class_exists(LoginSuccessEvent::class)) {
            $this->markTestSkipped('This test is incompatible with versions of Symfony where the LoginSuccessEvent event exists.');
        }

        $this->hub->expects($this->once())
            ->method('getClient')
            ->willReturn(null);

        $this->hub->expects($this->never())
            ->method('configureScope');

        if (version_compare(Kernel::VERSION, '5.4', '<')) {
            $this->listener->handleAuthenticationSuccessEvent(new AuthenticationSuccessEvent(new LegacyAuthenticatedTokenStub(new UserWithIdentifierStub())));
        } else {
            $this->listener->handleAuthenticationSuccessEvent(new AuthenticationSuccessEvent(new AuthenticatedTokenStub(new UserWithIdentifierStub())));
        }
    }

    public function testHandleAuthenticationSuccessEventDoesNothingIfSendingDefaultPiiIsDisabled(): void
    {
        if (class_exists(LoginSuccessEvent::class)) {
            $this->markTestSkipped('This test is incompatible with versions of Symfony where the LoginSuccessEvent event exists.');
        }

        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())
            ->method('getOptions')
            ->willReturn(new Options(['send_default_pii' => false]));

        $this->hub->expects($this->once())
            ->method('getClient')
            ->willReturn($client);

        $this->hub->expects($this->never())
            ->method('configureScope');

        if (version_compare(Kernel::VERSION, '5.4', '<')) {
            $this->listener->handleAuthenticationSuccessEvent(new AuthenticationSuccessEvent(new LegacyAuthenticatedTokenStub(new UserWithIdentifierStub())));
        } else {
            $this->listener->handleAuthenticationSuccessEvent(new AuthenticationSuccessEvent(new AuthenticatedTokenStub(new UserWithIdentifierStub())));
        }
    }
}

final class UnauthenticatedTokenStub extends AbstractToken
{
    public function isAuthenticated(): bool
    {
        return false;
    }

    public function getCredentials(): ?string
    {
        return null;
    }
}

class LegacyAuthenticatedTokenStub extends AbstractToken
{
    /**
     * @var bool
     *
     * @phpstan-ignore-next-line
     */
    private $authenticated = false;

    /**
     * @param UserInterface|\Stringable|string|null $user
     */
    public function __construct($user)
    {
        parent::__construct();

        if (null !== $user) {
            // @phpstan-ignore-next-line
            $this->setUser($user);
        }

        if (version_compare(Kernel::VERSION, '5.4', '<') && method_exists($this, 'setAuthenticated')) {
            $this->setAuthenticated(true);
        } else {
            $this->authenticated = true;
        }
    }

    public function getCredentials(): ?string
    {
        return null;
    }
}

final class AuthenticatedTokenStub extends AbstractToken
{
    /**
     * @var bool
     */
    private $authenticated = false;

    /**
     * @param UserInterface|\Stringable|string|null $user
     */
    public function __construct($user)
    {
        parent::__construct();

        if (null !== $user) {
            // @phpstan-ignore-next-line
            $this->setUser($user);
        }

        if (version_compare(Kernel::VERSION, '5.4', '<') && method_exists($this, 'setAuthenticated')) {
            $this->setAuthenticated(true);
        } else {
            $this->authenticated = true;
        }
    }

    public function isAuthenticated(): bool
    {
        return $this->authenticated;
    }

    public function getCredentials(): ?string
    {
        return null;
    }
}
