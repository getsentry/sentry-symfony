<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\EventListener;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\EventListener\ErrorListener;
use Sentry\State\HubInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ErrorListenerTest extends TestCase
{
    /**
     * @var MockObject&HubInterface
     */
    private $hub;

    /**
     * @var ErrorListener
     */
    private $listener;

    protected function setUp(): void
    {
        $this->hub = $this->createMock(HubInterface::class);
        $this->listener = new ErrorListener($this->hub);
    }

    /**
     * @dataProvider handleExceptionEventDataProvider
     */
    public function testHandleExceptionEvent(ExceptionEvent $event): void
    {
        $this->hub->expects($this->once())
            ->method('captureException')
            ->with($event->getThrowable());

        $this->listener->handleExceptionEvent($event);
    }

    /**
     * @return \Generator<mixed>
     */
    public function handleExceptionEventDataProvider(): \Generator
    {
        yield [
            new ExceptionEvent(
                $this->createMock(HttpKernelInterface::class),
                new Request(),
                HttpKernelInterface::MASTER_REQUEST,
                new \Exception()
            ),
        ];
    }
}
