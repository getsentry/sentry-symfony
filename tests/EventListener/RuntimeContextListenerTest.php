<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\EventListener;

use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\EventListener\RuntimeContextListener;
use Sentry\SentrySdk;
use Sentry\State\HubInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class RuntimeContextListenerTest extends TestCase
{
    /**
     * @var RuntimeContextListener
     */
    private $listener;

    protected function setUp(): void
    {
        SentrySdk::init();

        $this->listener = new RuntimeContextListener($this->createMock(HubInterface::class));
    }

    protected function tearDown(): void
    {
        SentrySdk::endContext();
    }

    public function testHandleKernelRequestEventStartsContextForMainRequest(): void
    {
        $globalHub = SentrySdk::getCurrentHub();

        $this->listener->handleKernelRequestEvent($this->createRequestEvent($this->getMainRequestType()));

        $this->assertNotSame($globalHub, SentrySdk::getCurrentHub());
    }

    public function testHandleKernelRequestEventDoesNothingForSubRequest(): void
    {
        $globalHub = SentrySdk::getCurrentHub();

        $this->listener->handleKernelRequestEvent($this->createRequestEvent(HttpKernelInterface::SUB_REQUEST));

        $this->assertSame($globalHub, SentrySdk::getCurrentHub());
    }

    public function testHandleKernelTerminateEventEndsActiveContext(): void
    {
        $globalHub = SentrySdk::getCurrentHub();

        $this->listener->handleKernelRequestEvent($this->createRequestEvent($this->getMainRequestType()));

        $this->listener->handleKernelTerminateEvent(new TerminateEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request(),
            new Response()
        ));

        $this->assertSame($globalHub, SentrySdk::getCurrentHub());
    }

    public function testResetEndsActiveContext(): void
    {
        $globalHub = SentrySdk::getCurrentHub();

        $this->listener->handleKernelRequestEvent($this->createRequestEvent($this->getMainRequestType()));

        $this->listener->reset();

        $this->assertSame($globalHub, SentrySdk::getCurrentHub());
    }

    private function createRequestEvent(int $requestType): RequestEvent
    {
        return new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request(),
            $requestType
        );
    }

    private function getMainRequestType(): int
    {
        return (int) \constant(
            \defined(HttpKernelInterface::class . '::MAIN_REQUEST')
                ? HttpKernelInterface::class . '::MAIN_REQUEST'
                : HttpKernelInterface::class . '::MASTER_REQUEST'
        );
    }
}
