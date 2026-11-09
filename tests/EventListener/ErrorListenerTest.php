<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\EventListener;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sentry\EventHint;
use Sentry\SentryBundle\EventListener\ErrorListener;
use Sentry\SentryBundle\SentryManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ErrorListenerTest extends TestCase
{
    /**
     * @var MockObject&SentryManager
     */
    private $sentry;

    /**
     * @var ErrorListener
     */
    private $listener;

    protected function setUp(): void
    {
        $this->sentry = $this->createMock(SentryManager::class);
        $this->listener = new ErrorListener($this->sentry);
    }

    /**
     * @dataProvider handleExceptionEventDataProvider
     */
    public function testHandleExceptionEvent(ExceptionEvent $event): void
    {
        $expectedException = $event->getThrowable();

        $this->sentry->expects($this->once())
            ->method('captureEvent')
            ->with(
                $this->anything(),
                $this->logicalAnd(
                    $this->isInstanceOf(EventHint::class),
                    $this->callback(static function (EventHint $subject) use ($expectedException) {
                        self::assertSame($expectedException, $subject->exception);
                        self::assertNotNull($subject->mechanism);
                        self::assertFalse($subject->mechanism->isHandled());

                        return true;
                    })
                )
            );

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
                \defined(HttpKernelInterface::class . '::MAIN_REQUEST') ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::MASTER_REQUEST,
                new \Exception()
            ),
        ];
    }
}
