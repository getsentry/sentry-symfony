<?php

namespace Sentry\SentryBundle\Test\EventListener;

use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\EventListener\RequestListener;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

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

    public function testOnKernelRequestUserDataIsSetToScope(): void 
    {
        $listener = new RequestListener(
            $this->currentHub->reveal(),
            $this->prophesize(TokenStorageInterface::class)->reveal(),
            $this->prophesize(AuthorizationCheckerInterface::class)->reveal()
        );

        $event = $this->prophesize(GetResponseEvent::class);
        $event->isMasterRequest()
            ->willReturn(true);
        
        $listener->onKernelRequest($event->reveal());
        
        $this->markTestIncomplete();
        $this->assertSame([], $this->currentScope->getUser());
    }
}
