<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\EventListener;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\Options;
use Sentry\SentryBundle\EventListener\RequestListener;
use Sentry\SentryBundle\Tests\EventListener\Fixtures\UserWithIdentifierStub;
use Sentry\SentryBundle\Tests\EventListener\Fixtures\UserWithoutIdentifierStub;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Sentry\UserDataBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Security\Core\Authentication\Token\AbstractToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class RequestListenerTest extends TestCase
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
     * @var RequestListener
     */
    private $listener;

    protected function setUp(): void
    {
        $this->hub = $this->createMock(HubInterface::class);
        $this->tokenStorage = $this->createMock(TokenStorageInterface::class);
        $this->listener = new RequestListener($this->hub, $this->tokenStorage);
    }

    /**
     * @dataProvider handleKernelRequestEventDataProvider
     * @dataProvider handleKernelRequestEventForSymfonyVersionLowerThan54DataProvider
     * @dataProvider handleKernelRequestEventForSymfonyVersionGreaterThan54DataProvider
     * @dataProvider handleKernelRequestEventForSymfonyVersionLowerThan60DataProvider
     */
    public function testHandleKernelRequestEvent(RequestEvent $requestEvent, ?ClientInterface $client, ?TokenInterface $token, ?UserDataBag $expectedUser): void
    {
        $scope = new Scope();

        $this->hub->expects($this->any())
            ->method('getClient')
            ->willReturn($client);

        $this->tokenStorage->expects($this->any())
            ->method('getToken')
            ->willReturn($token);

        $this->hub->expects($this->any())
            ->method('configureScope')
            ->willReturnCallback(static function (callable $callback) use ($scope): void {
                $callback($scope);
            });

        $this->listener->handleKernelRequestEvent($requestEvent);

        $event = $scope->applyToEvent(Event::createEvent());

        $this->assertNotNull($event);
        $this->assertEquals($expectedUser, $event->getUser());
    }

    /**
     * @return \Generator<mixed>
     */
    public function handleKernelRequestEventDataProvider(): \Generator
    {
        yield 'event.requestType != MASTER_REQUEST' => [
            new RequestEvent(
                $this->createMock(HttpKernelInterface::class),
                new Request([], [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1']),
                HttpKernelInterface::SUB_REQUEST
            ),
            $this->getMockedClientWithOptions(new Options(['send_default_pii' => true])),
            null,
            null,
        ];

        yield 'options.send_default_pii = FALSE' => [
            new RequestEvent(
                $this->createMock(HttpKernelInterface::class),
                new Request([], [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1']),
                HttpKernelInterface::MASTER_REQUEST
            ),
            $this->getMockedClientWithOptions(new Options(['send_default_pii' => false])),
            null,
            null,
        ];

        yield 'token IS NULL' => [
            new RequestEvent(
                $this->createMock(HttpKernelInterface::class),
                new Request([], [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1']),
                HttpKernelInterface::MASTER_REQUEST
            ),
            $this->getMockedClientWithOptions(new Options(['send_default_pii' => true])),
            null,
            UserDataBag::createFromUserIpAddress('127.0.0.1'),
        ];

        yield 'token.authenticated = FALSE' => [
            new RequestEvent(
                $this->createMock(HttpKernelInterface::class),
                new Request([], [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1']),
                HttpKernelInterface::MASTER_REQUEST
            ),
            $this->getMockedClientWithOptions(new Options(['send_default_pii' => true])),
            new UnauthenticatedTokenStub(),
            UserDataBag::createFromUserIpAddress('127.0.0.1'),
        ];

        yield 'token.authenticated = TRUE && token.user IS NULL' => [
            new RequestEvent(
                $this->createMock(HttpKernelInterface::class),
                new Request([], [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1']),
                HttpKernelInterface::MASTER_REQUEST
            ),
            $this->getMockedClientWithOptions(new Options(['send_default_pii' => true])),
            new AuthenticatedTokenStub(null),
            UserDataBag::createFromUserIpAddress('127.0.0.1'),
        ];

        yield 'token.authenticated = TRUE && token.user INSTANCEOF UserInterface && getUserIdentifier() method EXISTS' => [
            new RequestEvent(
                $this->createMock(HttpKernelInterface::class),
                new Request([], [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1']),
                HttpKernelInterface::MASTER_REQUEST
            ),
            $this->getMockedClientWithOptions(new Options(['send_default_pii' => true])),
            new AuthenticatedTokenStub(new UserWithIdentifierStub()),
            new UserDataBag(null, null, '127.0.0.1', 'foo_user'),
        ];

        yield 'request.clientIp IS NULL' => [
            new RequestEvent(
                $this->createMock(HttpKernelInterface::class),
                new Request(),
                HttpKernelInterface::MASTER_REQUEST
            ),
            $this->getMockedClientWithOptions(new Options(['send_default_pii' => true])),
            null,
            new UserDataBag(),
        ];
    }

    /**
     * @return \Generator<mixed>
     */
    public function handleKernelRequestEventForSymfonyVersionLowerThan54DataProvider(): \Generator
    {
        if (version_compare(Kernel::VERSION, '5.4.0', '>=')) {
            return;
        }

        yield 'token.authenticated = TRUE && token INSTANCEOF SwitchUserToken' => [
            new RequestEvent(
                $this->createMock(HttpKernelInterface::class),
                new Request([], [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1']),
                HttpKernelInterface::MASTER_REQUEST
            ),
            $this->getMockedClientWithOptions(new Options(['send_default_pii' => true])),
            new SwitchUserToken(
                new UserWithIdentifierStub(),
                '',
                'user_provider',
                ['ROLE_USER'],
                new AuthenticatedTokenStub(new UserWithIdentifierStub('foo_user_impersonator'))
            ),
            UserDataBag::createFromArray([
                'ip_address' => '127.0.0.1',
                'username' => 'foo_user',
                'impersonator_username' => 'foo_user_impersonator',
            ]),
        ];
    }

    /**
     * @return \Generator<mixed>
     */
    public function handleKernelRequestEventForSymfonyVersionGreaterThan54DataProvider(): \Generator
    {
        if (version_compare(Kernel::VERSION, '5.4.0', '<')) {
            return;
        }

        yield 'token.authenticated = TRUE && token INSTANCEOF SwitchUserToken' => [
            new RequestEvent(
                $this->createMock(HttpKernelInterface::class),
                new Request([], [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1']),
                HttpKernelInterface::MASTER_REQUEST
            ),
            $this->getMockedClientWithOptions(new Options(['send_default_pii' => true])),
            new SwitchUserToken(
                new UserWithIdentifierStub(),
                'main',
                ['ROLE_USER'],
                new AuthenticatedTokenStub(new UserWithIdentifierStub('foo_user_impersonator'))
            ),
            UserDataBag::createFromArray([
                'ip_address' => '127.0.0.1',
                'username' => 'foo_user',
                'impersonator_username' => 'foo_user_impersonator',
            ]),
        ];
    }

    /**
     * @return \Generator<mixed>
     */
    public function handleKernelRequestEventForSymfonyVersionLowerThan60DataProvider(): \Generator
    {
        if (version_compare(Kernel::VERSION, '6.0.0', '>=')) {
            return;
        }

        yield 'token.authenticated = TRUE && token.user INSTANCEOF string' => [
            new RequestEvent(
                $this->createMock(HttpKernelInterface::class),
                new Request([], [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1']),
                HttpKernelInterface::MASTER_REQUEST
            ),
            $this->getMockedClientWithOptions(new Options(['send_default_pii' => true])),
            new AuthenticatedTokenStub('foo_user'),
            new UserDataBag(null, null, '127.0.0.1', 'foo_user'),
        ];

        yield 'token.authenticated = TRUE && token.user INSTANCEOF UserInterface && getUserIdentifier() method DOES NOT EXISTS' => [
            new RequestEvent(
                $this->createMock(HttpKernelInterface::class),
                new Request([], [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1']),
                HttpKernelInterface::MASTER_REQUEST
            ),
            $this->getMockedClientWithOptions(new Options(['send_default_pii' => true])),
            new AuthenticatedTokenStub(new UserWithoutIdentifierStub()),
            new UserDataBag(null, null, '127.0.0.1', 'foo_user'),
        ];

        yield 'token.authenticated = TRUE && token.user INSTANCEOF object && __toString() method EXISTS' => [
            new RequestEvent(
                $this->createMock(HttpKernelInterface::class),
                new Request([], [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1']),
                HttpKernelInterface::MASTER_REQUEST
            ),
            $this->getMockedClientWithOptions(new Options(['send_default_pii' => true])),
            new AuthenticatedTokenStub(new class() implements \Stringable {
                public function __toString(): string
                {
                    return 'foo_user';
                }
            }),
            new UserDataBag(null, null, '127.0.0.1', 'foo_user'),
        ];
    }

    /**
     * @dataProvider handleKernelControllerEventDataProvider
     *
     * @param array<string, string> $expectedTags
     */
    public function testHandleKernelControllerEvent(ControllerEvent $controllerEvent, array $expectedTags): void
    {
        $scope = new Scope();

        $this->hub->expects($this->any())
            ->method('configureScope')
            ->willReturnCallback(static function (callable $callback) use ($scope): void {
                $callback($scope);
            });

        $this->listener->handleKernelControllerEvent($controllerEvent);

        $event = $scope->applyToEvent(Event::createEvent());

        $this->assertNotNull($event);
        $this->assertSame($expectedTags, $event->getTags());
    }

    /**
     * @return \Generator<mixed>
     */
    public function handleKernelControllerEventDataProvider(): \Generator
    {
        yield 'event.requestType != MASTER_REQUEST' => [
            new ControllerEvent(
                $this->createMock(HttpKernelInterface::class),
                static function () {
                },
                new Request([], [], ['_route' => 'homepage']),
                HttpKernelInterface::SUB_REQUEST
            ),
            [],
        ];

        yield 'event.requestType = MASTER_REQUEST && request.attributes._route NOT EXISTS ' => [
            new ControllerEvent(
                $this->createMock(HttpKernelInterface::class),
                static function () {
                },
                new Request(),
                HttpKernelInterface::MASTER_REQUEST
            ),
            [],
        ];

        yield 'event.requestType = MASTER_REQUEST && request.attributes._route EXISTS' => [
            new ControllerEvent(
                $this->createMock(HttpKernelInterface::class),
                static function () {
                },
                new Request([], [], ['_route' => 'homepage']),
                HttpKernelInterface::MASTER_REQUEST
            ),
            [
                'route' => 'homepage',
            ],
        ];
    }

    private function getMockedClientWithOptions(Options $options): ClientInterface
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->any())
            ->method('getOptions')
            ->willReturn($options);

        return $client;
    }
}

final class UnauthenticatedTokenStub extends AbstractToken
{
    public function getCredentials(): ?string
    {
        return null;
    }
}

final class AuthenticatedTokenStub extends AbstractToken
{
    /**
     * @param UserInterface|\Stringable|string|null $user
     */
    public function __construct($user)
    {
        parent::__construct();

        if (method_exists($this, 'setAuthenticated')) {
            $this->setAuthenticated(true);
        }

        if (null !== $user) {
            $this->setUser($user);
        }
    }

    public function getCredentials(): ?string
    {
        return null;
    }
}
