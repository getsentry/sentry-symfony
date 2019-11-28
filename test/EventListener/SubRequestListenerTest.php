<?php

namespace Sentry\SentryBundle\Test\EventListener;

use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\EventListener\SubRequestListener;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

class SubRequestListenerTest extends TestCase
{
    private $currentHub;

    protected function setUp()
    {
        parent::setUp();

        $this->currentHub = $this->prophesize(HubInterface::class);

        Hub::setCurrent($this->currentHub->reveal());
    }

    public function testOnKernelRequestWithMasterRequest(): void
    {
        $listener = new SubRequestListener();

        $subRequestEvent = $this->prophesize(\class_exists(ResponseEvent::class) ? ResponseEvent::class : GetResponseEvent::class);
        $subRequestEvent->isMasterRequest()
            ->willReturn(true);

        $this->currentHub->pushScope()
            ->shouldNotBeCalled();

        $listener->onKernelRequest($subRequestEvent->reveal());
    }

    public function testOnKernelRequestWithSubRequest(): void
    {
        $listener = new SubRequestListener();

        $subRequestEvent = $this->prophesize(\class_exists(ResponseEvent::class) ? ResponseEvent::class : GetResponseEvent::class);
        $subRequestEvent->isMasterRequest()
            ->willReturn(false);

        $this->currentHub->pushScope()
            ->shouldBeCalledTimes(1)
            ->willReturn(new Scope());

        $listener->onKernelRequest($subRequestEvent->reveal());
    }

    public function testOnKernelFinishRequestWithMasterRequest(): void
    {
        $listener = new SubRequestListener();

        $subRequestEvent = $this->prophesize(FinishRequestEvent::class);
        $subRequestEvent->isMasterRequest()
            ->willReturn(true);

        $this->currentHub->popScope()
            ->shouldNotBeCalled();

        $listener->onKernelFinishRequest($subRequestEvent->reveal());
    }

    public function testOnKernelFinishRequestWithSubRequest(): void
    {
        $listener = new SubRequestListener();

        $subRequestEvent = $this->prophesize(FinishRequestEvent::class);
        $subRequestEvent->isMasterRequest()
            ->willReturn(false);

        $this->currentHub->popScope()
            ->shouldBeCalledTimes(1)
            ->willReturn(true);

        $listener->onKernelFinishRequest($subRequestEvent->reveal());
    }
}
