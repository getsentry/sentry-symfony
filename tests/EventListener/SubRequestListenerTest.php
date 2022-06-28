<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\EventListener;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\EventListener\KernelEventForwardCompatibilityTrait;
use Sentry\SentryBundle\EventListener\SubRequestListener;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class SubRequestListenerTest extends TestCase
{
    use KernelEventForwardCompatibilityTrait;

    /**
     * @var MockObject&HubInterface
     */
    private $hub;

    /**
     * @var SubRequestListener
     */
    private $listener;

    protected function setUp(): void
    {
        $this->hub = $this->createMock(HubInterface::class);
        $this->listener = new SubRequestListener($this->hub);
    }

    /**
     * @dataProvider handleKernelRequestEventDataProvider
     */
    public function testHandleKernelRequestEvent(RequestEvent $event): void
    {
        $this->hub->expects($this->isMainRequest($event) ? $this->never() : $this->once())
            ->method('pushScope')
            ->willReturn(new Scope());

        $this->listener->handleKernelRequestEvent($event);
    }

    /**
     * @return \Generator<mixed>
     */
    public function handleKernelRequestEventDataProvider(): \Generator
    {
        yield [
            new RequestEvent($this->createMock(HttpKernelInterface::class), new Request(), HttpKernelInterface::MASTER_REQUEST),
        ];

        yield [
            new RequestEvent($this->createMock(HttpKernelInterface::class), new Request(), HttpKernelInterface::SUB_REQUEST),
        ];
    }

    /**
     * @dataProvider handleKernelFinishRequestEventDataProvider
     *
     * @param FinishRequestEvent $event
     */
    public function testHandleKernelFinishRequestEvent($event): void
    {
        $this->hub->expects($this->isMainRequest($event) ? $this->never() : $this->once())
            ->method('popScope');

        $this->listener->handleKernelFinishRequestEvent($event);
    }

    /**
     * @return \Generator<mixed>
     */
    public function handleKernelFinishRequestEventDataProvider(): \Generator
    {
        yield [
            new FinishRequestEvent($this->createMock(HttpKernelInterface::class), new Request(), HttpKernelInterface::MASTER_REQUEST),
        ];

        yield [
            new FinishRequestEvent($this->createMock(HttpKernelInterface::class), new Request(), HttpKernelInterface::SUB_REQUEST),
        ];
    }
}
