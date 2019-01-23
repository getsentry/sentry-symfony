<?php

namespace Sentry\SentryBundle\Test\EventListener;

use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\EventListener\RequestListener;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\FilterControllerEvent;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\AuthenticatedVoter;
use Symfony\Component\Security\Core\User\UserInterface;

class RequestListenerTest extends TestCase
{
    private $currentScope;
    private $currentHub;

    protected function setUp()
    {
        parent::setUp();

        $this->currentScope = new Scope();
        $this->currentHub = $this->prophesize(HubInterface::class);
        $this->currentHub->getScope()
            ->shouldBeCalled()
            ->willReturn($this->currentScope);

        Hub::setCurrent($this->currentHub->reveal());
    }

    /**
     * @dataProvider userDataProvider
     */
    public function testOnKernelRequestUserDataIsSetToScope($user): void
    {
        $tokenStorage = $this->prophesize(TokenStorageInterface::class);
        $authorizationChecker = $this->prophesize(AuthorizationCheckerInterface::class);
        $event = $this->prophesize(GetResponseEvent::class);
        $request = $this->prophesize(Request::class);
        $token = $this->prophesize(TokenInterface::class);

        $event->isMasterRequest()
            ->willReturn(true);

        $tokenStorage->getToken()
            ->willReturn($token->reveal());

        $token->isAuthenticated()
            ->willReturn(true);
        $authorizationChecker->isGranted(AuthenticatedVoter::IS_AUTHENTICATED_REMEMBERED)
            ->willReturn(true);

        $token->getUser()
            ->willReturn($user);

        $event->getRequest()
            ->willReturn($request->reveal());
        $request->getClientIp()
            ->willReturn('1.2.3.4');

        $listener = new RequestListener(
            $this->currentHub->reveal(),
            $tokenStorage->reveal(),
            $authorizationChecker->reveal()
        );

        $listener->onKernelRequest($event->reveal());

        $expectedUserData = [
            'ip_address' => '1.2.3.4',
            'username' => 'john-doe',
        ];
        $this->assertEquals($expectedUserData, $this->currentScope->getUser());
    }

    public function userDataProvider(): \Generator
    {
        yield ['john-doe'];

        $userInterface = $this->prophesize(UserInterface::class);
        $userInterface->getUsername()
            ->willReturn('john-doe');

        yield [$userInterface->reveal()];
        yield [new ToStringUser('john-doe')];
    }

    public function testOnKernelControllerAddsRouteTag(): void
    {
        $event = $this->prophesize(FilterControllerEvent::class);
        $request = $this->prophesize(Request::class);
        $attributes = new ParameterBag();
        $attributes->set('_route', 'sf-route');

        $event->isMasterRequest()
            ->willReturn(true);
        $event->getRequest()
            ->willReturn($request->reveal());

        $request->attributes = $attributes;

        $listener = new RequestListener(
            $this->currentHub->reveal(),
            $this->prophesize(TokenStorageInterface::class)->reveal(),
            $this->prophesize(AuthorizationCheckerInterface::class)->reveal()
        );

        $listener->onKernelController($event->reveal());

        $this->assertSame(['route' => 'sf-route'], $this->currentScope->getTags());
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
