<?php

namespace Sentry\SentryBundle\Test\EventListener;

use Sentry\SentryBundle\EventListener\SubRequestListener;
use Sentry\SentryBundle\Test\BaseTestCase;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Symfony\Bridge\PhpUnit\SetUpTearDownTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\KernelInterface;

class SubRequestListenerTest extends BaseTestCase
{
    use SetUpTearDownTrait;

    private $currentHub;

    protected function doSetUp(): void
    {
        $this->currentHub = $this->prophesize(HubInterface::class);

        $this->setCurrentHub($this->currentHub->reveal());
    }

    public function testOnKernelRequestWithMasterRequest(): void
    {
        $listener = new SubRequestListener();

        $masterRequestEvent = $this->createRequestEvent();

        $this->currentHub->pushScope()
            ->shouldNotBeCalled();

        $listener->onKernelRequest($masterRequestEvent);
    }

    public function testOnKernelRequestWithSubRequest(): void
    {
        $listener = new SubRequestListener();

        $subRequestEvent = $this->createRequestEvent(null, KernelInterface::SUB_REQUEST);

        $this->currentHub->pushScope()
            ->shouldBeCalledTimes(1)
            ->willReturn(new Scope());

        $listener->onKernelRequest($subRequestEvent);
    }

    public function testOnKernelFinishRequestWithMasterRequest(): void
    {
        $listener = new SubRequestListener();

        $masterRequestEvent = $this->createFinishRequestEvent(KernelInterface::MASTER_REQUEST);

        $this->currentHub->popScope()
            ->shouldNotBeCalled();

        $listener->onKernelFinishRequest($masterRequestEvent);
    }

    public function testOnKernelFinishRequestWithSubRequest(): void
    {
        $listener = new SubRequestListener();

        $subRequestEvent = $this->createFinishRequestEvent(KernelInterface::SUB_REQUEST);

        $this->currentHub->popScope()
            ->shouldBeCalledTimes(1)
            ->willReturn(true);

        $listener->onKernelFinishRequest($subRequestEvent);
    }

    private function createFinishRequestEvent(int $type): FinishRequestEvent
    {
        return new FinishRequestEvent(
            $this->prophesize(KernelInterface::class)->reveal(),
            $this->prophesize(Request::class)->reveal(),
            $type
        );
    }
}
