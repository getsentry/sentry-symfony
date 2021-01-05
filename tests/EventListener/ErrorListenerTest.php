<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\EventListener;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\EventListener\ErrorListener;
use Sentry\State\HubInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Kernel;

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
     * @dataProvider handleExceptionEventForSymfonyVersionAtLeast43DataProvider
     * @dataProvider handleExceptionEventForSymfonyVersionLowerThan43DataProvider
     *
     * @param ExceptionEvent|GetResponseForExceptionEvent $event
     */
    public function testHandleExceptionEvent($event): void
    {
        $this->hub->expects($this->once())
            ->method('captureException')
            ->with($event instanceof ExceptionEvent ? $event->getThrowable() : $event->getException());

        $this->listener->handleExceptionEvent($event);
    }

    /**
     * @return \Generator<mixed>
     */
    public function handleExceptionEventForSymfonyVersionLowerThan43DataProvider(): \Generator
    {
        if (version_compare(Kernel::VERSION, '4.3.0', '>=')) {
            return;
        }

        yield [
            new GetResponseForExceptionEvent(
                $this->createMock(HttpKernelInterface::class),
                new Request(),
                HttpKernelInterface::MASTER_REQUEST,
                new \Exception()
            ),
        ];
    }

    /**
     * @return \Generator<mixed>
     */
    public function handleExceptionEventForSymfonyVersionAtLeast43DataProvider(): \Generator
    {
        if (version_compare(Kernel::VERSION, '4.3.0', '<')) {
            return;
        }

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
