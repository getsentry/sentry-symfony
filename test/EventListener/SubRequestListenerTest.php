<?php

namespace Sentry\SentryBundle\Test\EventListener;

use Sentry\SentryBundle\EventListener\SubRequestListener;
use Sentry\SentryBundle\Test\BaseTestCase;
use Sentry\SentrySdk;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelInterface;

class SubRequestListenerTest extends BaseTestCase
{
    private $currentHub;

    protected function setUp(): void
    {
        $this->currentHub = $this->prophesize(HubInterface::class);

        SentrySdk::setCurrentHub($this->currentHub->reveal());
    }

    public function testOnKernelRequestWithMasterRequest(): void
    {
        $listener = new SubRequestListener();

        $masterRequestEvent = $this->createRequestEvent();

        $this->currentHub->pushScope()
            ->shouldNotBeCalled();

        $this->callOnRequest($listener, $masterRequestEvent);
    }

    public function testOnKernelRequestWithSubRequest(): void
    {
        $listener = new SubRequestListener();

        $subRequestEvent = $this->createRequestEvent(null, KernelInterface::SUB_REQUEST);

        $this->currentHub->pushScope()
            ->shouldBeCalledTimes(1)
            ->willReturn(new Scope());

        $this->callOnRequest($listener, $subRequestEvent);
    }

    public function testonFinishRequestWithMasterRequest(): void
    {
        $listener = new SubRequestListener();

        $masterRequestEvent = $this->createFinishRequestEvent(KernelInterface::MASTER_REQUEST);

        $this->currentHub->popScope()
            ->shouldNotBeCalled();

        $listener->onFinishRequest($masterRequestEvent);
    }

    public function testonFinishRequestWithSubRequest(): void
    {
        $listener = new SubRequestListener();

        $subRequestEvent = $this->createFinishRequestEvent(KernelInterface::SUB_REQUEST);

        $this->currentHub->popScope()
            ->shouldBeCalledTimes(1)
            ->willReturn(true);

        $listener->onFinishRequest($subRequestEvent);
    }

    private function createFinishRequestEvent(int $type): FinishRequestEvent
    {
        return new FinishRequestEvent(
            $this->prophesize(KernelInterface::class)->reveal(),
            $this->prophesize(Request::class)->reveal(),
            $type
        );
    }

    /**
     * @param SubRequestListener $listener
     * @param $event
     */
    private function callOnRequest(SubRequestListener $listener, $event): void
    {
        if (class_exists(RequestEvent::class)) {
            $listener->onRequest($event);
        } else {
            $listener->onKernelRequest($event);
        }
    }
}
