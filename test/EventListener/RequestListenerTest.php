<?php

namespace Sentry\SentryBundle\Test\EventListener;

use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\Options;
use Sentry\SentryBundle\EventListener\RequestListener;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\FilterControllerEvent;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class RequestListenerTest extends TestCase
{
    private $currentScope;
    private $currentHub;
    private $options;

    protected function setUp()
    {
        parent::setUp();

        $this->currentScope = $scope = new Scope();
        $this->currentHub = $this->prophesize(HubInterface::class);

        $client = $this->prophesize(ClientInterface::class);
        $this->options = new Options();

        $this->currentHub->getClient()
            ->willReturn($client->reveal());
        $client->getOptions()
            ->willReturn($this->options);
        $this->options->setSendDefaultPii(true);

        $this->currentHub->configureScope(Argument::type('callable'))
            ->shouldBeCalled()
            ->will(function ($arguments) use ($scope): void {
                $callable = $arguments[0];

                $callable($scope);
            });

        Hub::setCurrent($this->currentHub->reveal());
    }

    /**
     * @dataProvider userDataProvider
     */
    public function testOnKernelRequestUserDataIsSetToScope($user): void
    {
        $tokenStorage = $this->prophesize(TokenStorageInterface::class);
        $event = $this->prophesize(GetResponseEvent::class);
        $request = $this->prophesize(Request::class);
        $token = $this->prophesize(TokenInterface::class);

        $event->isMasterRequest()
            ->willReturn(true);

        $tokenStorage->getToken()
            ->willReturn($token->reveal());

        $token->isAuthenticated()
            ->willReturn(true);

        $token->getUser()
            ->willReturn($user);

        $event->getRequest()
            ->willReturn($request->reveal());
        $request->getClientIp()
            ->willReturn('1.2.3.4');

        $listener = new RequestListener(
            $this->currentHub->reveal(),
            $tokenStorage->reveal()
        );

        $listener->onKernelRequest($event->reveal());

        $expectedUserData = [
            'ip_address' => '1.2.3.4',
            'username' => 'john-doe',
        ];
        $this->assertEquals($expectedUserData, $this->getUserContext($this->currentScope));
    }

    public function userDataProvider(): \Generator
    {
        yield ['john-doe'];
        yield [new UserWithInterface('john-doe')];
        yield [new ToStringUser('john-doe')];
    }

    public function testOnKernelRequestUserDataIsNotSetIfSendPiiIsDisabled(): void
    {
        $tokenStorage = $this->prophesize(TokenStorageInterface::class);
        $event = $this->prophesize(GetResponseEvent::class);

        $event->isMasterRequest()
            ->willReturn(true);

        $this->options->setSendDefaultPii(false);

        $this->currentHub->configureScope(Argument::type('callable'))
            ->shouldNotBeCalled();

        $listener = new RequestListener(
            $this->currentHub->reveal(),
            $tokenStorage->reveal()
        );

        $listener->onKernelRequest($event->reveal());

        $this->assertEquals([], $this->getUserContext($this->currentScope));
    }

    public function testOnKernelRequestUserDataIsNotSetIfNoClientIsPresent(): void
    {
        $tokenStorage = $this->prophesize(TokenStorageInterface::class);
        $event = $this->prophesize(GetResponseEvent::class);

        $event->isMasterRequest()
            ->willReturn(true);

        $this->currentHub->getClient()
            ->willReturn(null);
        $this->currentHub->configureScope(Argument::type('callable'))
            ->shouldNotBeCalled();

        $listener = new RequestListener(
            $this->currentHub->reveal(),
            $tokenStorage->reveal()
        );

        $listener->onKernelRequest($event->reveal());

        $this->assertEquals([], $this->getUserContext($this->currentScope));
    }

    public function testOnKernelRequestUsernameIsNotSetIfTokenStorageIsAbsent(): void
    {
        $event = $this->prophesize(GetResponseEvent::class);
        $request = $this->prophesize(Request::class);

        $event->isMasterRequest()
            ->willReturn(true);

        $event->getRequest()
            ->willReturn($request->reveal());
        $request->getClientIp()
            ->willReturn('1.2.3.4');

        $listener = new RequestListener(
            $this->currentHub->reveal(),
            null
        );

        $listener->onKernelRequest($event->reveal());

        $expectedUserData = [
            'ip_address' => '1.2.3.4',
        ];
        $this->assertEquals($expectedUserData, $this->getUserContext($this->currentScope));
    }

    public function testOnKernelRequestUsernameIsNotSetIfTokenIsAbsent(): void
    {
        $tokenStorage = $this->prophesize(TokenStorageInterface::class);
        $event = $this->prophesize(GetResponseEvent::class);
        $request = $this->prophesize(Request::class);

        $event->isMasterRequest()
            ->willReturn(true);

        $tokenStorage->getToken()
            ->willReturn(null);

        $event->getRequest()
            ->willReturn($request->reveal());
        $request->getClientIp()
            ->willReturn('1.2.3.4');

        $listener = new RequestListener(
            $this->currentHub->reveal(),
            $tokenStorage->reveal()
        );

        $listener->onKernelRequest($event->reveal());

        $expectedUserData = [
            'ip_address' => '1.2.3.4',
        ];
        $this->assertEquals($expectedUserData, $this->getUserContext($this->currentScope));
    }

    /**
     * @ticket #78
     */
    public function testOnKernelRequestUsernameIsNotSetIfTokenIsNotAuthenticated(): void
    {
        $tokenStorage = $this->prophesize(TokenStorageInterface::class);
        $token = $this->prophesize(TokenInterface::class);
        $event = $this->prophesize(GetResponseEvent::class);
        $request = $this->prophesize(Request::class);

        $event->isMasterRequest()
            ->willReturn(true);

        $tokenStorage->getToken()
            ->willReturn($token->reveal());

        $token->isAuthenticated()
            ->willReturn(false);

        $event->getRequest()
            ->willReturn($request->reveal());
        $request->getClientIp()
            ->willReturn('1.2.3.4');

        $listener = new RequestListener(
            $this->currentHub->reveal(),
            $tokenStorage->reveal()
        );

        $listener->onKernelRequest($event->reveal());

        $expectedUserData = [
            'ip_address' => '1.2.3.4',
        ];
        $this->assertEquals($expectedUserData, $this->getUserContext($this->currentScope));
    }

    public function testOnKernelRequestUsernameIsNotSetIfUserIsNotRemembered(): void
    {
        $tokenStorage = $this->prophesize(TokenStorageInterface::class);
        $event = $this->prophesize(GetResponseEvent::class);
        $request = $this->prophesize(Request::class);

        $event->isMasterRequest()
            ->willReturn(true);

        $tokenStorage->getToken()
            ->willReturn(null);

        $event->getRequest()
            ->willReturn($request->reveal());
        $request->getClientIp()
            ->willReturn('1.2.3.4');

        $listener = new RequestListener(
            $this->currentHub->reveal(),
            $tokenStorage->reveal()
        );

        $listener->onKernelRequest($event->reveal());

        $expectedUserData = [
            'ip_address' => '1.2.3.4',
        ];
        $this->assertEquals($expectedUserData, $this->getUserContext($this->currentScope));
    }

    public function testOnKernelControllerAddsRouteTag(): void
    {
        $request = new Request();
        $request->attributes->set('_route', 'sf-route');
        $event = $this->prophesize(FilterControllerEvent::class);

        $event->isMasterRequest()
            ->willReturn(true);
        $event->getRequest()
            ->willReturn($request);

        $listener = new RequestListener(
            $this->currentHub->reveal(),
            $this->prophesize(TokenStorageInterface::class)->reveal()
        );

        $listener->onKernelController($event->reveal());

        $this->assertSame(['route' => 'sf-route'], $this->getTagsContext($this->currentScope));
    }

    public function testOnKernelControllerRouteTagIsNotSetIfRequestDoesNotHaveARoute(): void
    {
        $this->currentHub->configureScope(Argument::type('callable'))
            ->shouldNotBeCalled();

        $request = new Request();
        $event = $this->prophesize(FilterControllerEvent::class);

        $event->isMasterRequest()
            ->willReturn(true);
        $event->getRequest()
            ->willReturn($request);

        $listener = new RequestListener(
            $this->currentHub->reveal(),
            $this->prophesize(TokenStorageInterface::class)->reveal()
        );

        $listener->onKernelController($event->reveal());
    }

    public function testOnKernelRequestUserDataAndTagsAreNotSetInSubRequest(): void
    {
        $this->currentHub->configureScope(Argument::type('callable'))
            ->shouldNotBeCalled();

        $tokenStorage = $this->prophesize(TokenStorageInterface::class);
        $event = $this->prophesize(GetResponseEvent::class);

        $event->isMasterRequest()
            ->willReturn(false);

        $tokenStorage->getToken()
            ->shouldNotBeCalled();

        $listener = new RequestListener(
            $this->currentHub->reveal(),
            $tokenStorage->reveal()
        );

        $listener->onKernelRequest($event->reveal());

        $this->assertEmpty($this->getUserContext($this->currentScope));
        $this->assertEmpty($this->getTagsContext($this->currentScope));
    }

    private function getUserContext(Scope $scope): array
    {
        $event = new Event();
        $scope->applyToEvent($event, []);

        return $event->getUserContext()->toArray();
    }

    private function getTagsContext(Scope $scope): array
    {
        $event = new Event();
        $scope->applyToEvent($event, []);

        return $event->getTagsContext()->toArray();
    }
}
class UserWithInterface implements UserInterface
{
    private $username;

    public function __construct(string $username)
    {
        $this->username = $username;
    }

    public function getRoles()
    {
        return [];
    }

    public function getPassword()
    {
        return null;
    }

    public function getSalt()
    {
        return null;
    }

    public function getUsername()
    {
        return $this->username;
    }

    public function eraseCredentials()
    {
    }
}

class ToStringUser
{
    private $username;

    public function __construct(string $username)
    {
        $this->username = $username;
    }

    public function __toString(): string
    {
        return $this->username;
    }
}
