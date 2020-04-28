<?php

namespace Sentry\SentryBundle\Test\EventListener;

use Sentry\SentryBundle\EventListener\SubRequestListener;
use Sentry\SentryBundle\Test\BaseTestCase;
use Sentry\SentrySdk;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\KernelInterface;

class SubRequestListenerTest extends BaseTestCase
{
    public function testOnKernelRequestWithMasterRequest(): void
    {
        $listener = new SubRequestListener();

        $masterRequestEvent = $this->createRequestEvent();

        $this->mockHub();

        $listener->onKernelRequest($masterRequestEvent);
    }

    public function testOnKernelRequestWithSubRequest(): void
    {
        $listener = new SubRequestListener();

        $subRequestEvent = $this->createRequestEvent(null, KernelInterface::SUB_REQUEST);

        $this->mockHub(1);

        $listener->onKernelRequest($subRequestEvent);
    }

    public function testOnKernelFinishRequestWithMasterRequest(): void
    {
        $listener = new SubRequestListener();

        $masterRequestEvent = $this->createFinishRequestEvent(KernelInterface::MASTER_REQUEST);

        $this->mockHub();

        $listener->onKernelFinishRequest($masterRequestEvent);
    }

    public function testOnKernelFinishRequestWithSubRequest(): void
    {
        $listener = new SubRequestListener();

        $subRequestEvent = $this->createFinishRequestEvent(KernelInterface::SUB_REQUEST);

        $this->mockHub(0, 1);

        $listener->onKernelFinishRequest($subRequestEvent);
    }

    private function mockHub(int $pushCount = 0, int $popCount = 0): void
    {
        $currentHub = $this->prophesize(HubInterface::class);
        SentrySdk::setCurrentHub($currentHub->reveal());

        $currentHub->pushScope()
            ->shouldBeCalledTimes($pushCount)
            ->willReturn(new Scope());

        $currentHub->popScope()
            ->shouldBeCalledTimes($popCount)
            ->willReturn(true);
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
