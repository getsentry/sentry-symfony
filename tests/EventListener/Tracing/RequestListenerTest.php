<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\EventListener\Tracing;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\EventListener\Tracing\RequestListener;
use Sentry\State\HubInterface;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\FilterControllerEvent;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event\PostResponseEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Kernel;

final class RequestListenerTest extends TestCase
{
    /**
     * @var MockObject&HubInterface
     */
    private $hub;

    /**
     * @var RequestListener
     */
    private $listener;

    protected function setUp(): void
    {
        $this->hub = $this->createMock(HubInterface::class);
        $this->listener = new RequestListener($this->hub);
    }

    /**
     * @dataProvider handleKernelRequestEventForSymfonyVersionAtLeast43DataProvider
     * @dataProvider handleKernelRequestEventForSymfonyVersionLowerThan43DataProvider
     *
     * @param GetResponseEvent|RequestEvent $requestEvent
     */
    public function testHandleKernelRequestEvent($requestEvent): void
    {
        $transaction = new Transaction(new TransactionContext());
        $transaction->initSpanRecorder();
        $transactionContext = null;

        $this->hub->expects($this->once())
            ->method('startTransaction')
            ->with($this->callback(function (TransactionContext $context) use (&$transactionContext) {
                $transactionContext = $context;

                return true;
            }))
            ->willReturnReference($transaction);

        $this->hub->expects($this->once())
            ->method('setSpan');

        $this->listener->handleKernelRequestEvent($requestEvent);

        if (!$transactionContext) {
            return;
        }

        $this->assertEquals('http.server', $transactionContext->getOp());
        $this->assertCount(2, $transaction->getSpanRecorder()->getSpans());
    }

    /**
     * @return \Generator<mixed>
     */
    public function handleKernelRequestEventForSymfonyVersionLowerThan43DataProvider(): \Generator
    {
        if (version_compare(Kernel::VERSION, '4.3.0', '>=')) {
            return;
        }

        yield 'event.requestType = MASTER_REQUEST && request.server.REQUEST_TIME_FLOAT NOT EXISTS' => [
            new GetResponseEvent(
                $this->createMock(HttpKernelInterface::class),
                new Request([], [], [], [], [], []),
                HttpKernelInterface::MASTER_REQUEST
            ),
        ];

        yield 'event.requestType = MASTER_REQUEST && request.server.REQUEST_TIME_FLOAT EXISTS' => [
            new GetResponseEvent(
                $this->createMock(HttpKernelInterface::class),
                new Request([], [], [], [], [], ['REQUEST_TIME_FLOAT' => microtime(true)]),
                HttpKernelInterface::MASTER_REQUEST
            ),
        ];
    }

    /**
     * @return \Generator<mixed>
     */
    public function handleKernelRequestEventForSymfonyVersionAtLeast43DataProvider(): \Generator
    {
        if (version_compare(Kernel::VERSION, '4.3.0', '<')) {
            return;
        }

        yield 'event.requestType = MASTER_REQUEST && request.server.REQUEST_TIME_FLOAT NOT EXISTS' => [
            new RequestEvent(
                $this->createMock(HttpKernelInterface::class),
                new Request([], [], [], [], [], []),
                HttpKernelInterface::MASTER_REQUEST
            ),
        ];

        yield 'event.requestType = MASTER_REQUEST && request.server.REQUEST_TIME_FLOAT EXISTS' => [
            new RequestEvent(
                $this->createMock(HttpKernelInterface::class),
                new Request([], [], [], [], [], ['REQUEST_TIME_FLOAT' => microtime(true)]),
                HttpKernelInterface::MASTER_REQUEST
            ),
        ];
    }

    /**
     * @dataProvider handleKernelControllerEventWithSymfonyVersionAtLeast43DataProvider
     * @dataProvider handleKernelControllerEventWithSymfonyVersionLowerThan43DataProvider
     *
     * @param GetResponseEvent|RequestEvent         $requestEvent
     * @param ControllerEvent|FilterControllerEvent $controllerEvent
     */
    public function testHandleControllerRequestEvent($requestEvent, $controllerEvent): void
    {
        $transaction = new Transaction(new TransactionContext());
        $transaction->initSpanRecorder();
        $transactionContext = null;

        $this->hub->expects($this->once())
            ->method('startTransaction')
            ->willReturnReference($transaction);

        $this->listener->handleKernelRequestEvent($requestEvent);
        $this->listener->handleKernelControllerEvent($controllerEvent);

        $this->assertCount(3, $transaction->getSpanRecorder()->getSpans());
    }

    /**
     * @return \Generator<mixed>
     */
    public function handleKernelControllerEventWithSymfonyVersionAtLeast43DataProvider(): \Generator
    {
        if (version_compare(Kernel::VERSION, '4.3.0', '<')) {
            return;
        }

        $requestEvent = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request([], [], [], [], [], []),
            HttpKernelInterface::MASTER_REQUEST
        );

        yield 'event.requestType = MASTER_REQUEST' => [
            $requestEvent,
            new ControllerEvent(
                $this->createMock(HttpKernelInterface::class),
                static function () {
                },
                new Request(),
                HttpKernelInterface::MASTER_REQUEST
            ),
            [],
        ];
    }

    /**
     * @return \Generator<mixed>
     */
    public function handleKernelControllerEventWithSymfonyVersionLowerThan43DataProvider(): \Generator
    {
        if (version_compare(Kernel::VERSION, '4.3.0', '>=')) {
            return;
        }

        $request = new GetResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request([], [], [], [], [], []),
            HttpKernelInterface::MASTER_REQUEST
        );

        yield 'event.requestType = MASTER_REQUEST' => [
            $request,
            new FilterControllerEvent(
                $this->createMock(HttpKernelInterface::class),
                static function () {
                },
                new Request(),
                HttpKernelInterface::MASTER_REQUEST
            ),
            [],
        ];
    }

    /**
     * @dataProvider handleKernelResponseEventWithSymfonyVersionAtLeast43DataProvider
     * @dataProvider handleKernelResponseEventWithSymfonyVersionLowerThan43DataProvider
     *
     * @param GetResponseEvent|RequestEvent         $requestEvent
     * @param ControllerEvent|FilterControllerEvent $controllerEvent
     * @param ResponseEvent|FilterResponseEvent     $responseEvent
     */
    public function testHandleResponseRequestEvent($requestEvent, $controllerEvent, $responseEvent): void
    {
        $transaction = new Transaction(new TransactionContext());
        $transaction->initSpanRecorder();
        $transactionContext = null;

        $this->hub->expects($this->once())
            ->method('startTransaction')
            ->willReturnReference($transaction);

        $this->listener->handleKernelRequestEvent($requestEvent);
        $this->listener->handleKernelControllerEvent($controllerEvent);
        $this->listener->handleKernelResponseEvent($responseEvent);

        $this->assertCount(4, $transaction->getSpanRecorder()->getSpans());
    }

    /**
     * @return \Generator<mixed>
     */
    public function handleKernelResponseEventWithSymfonyVersionAtLeast43DataProvider(): \Generator
    {
        if (version_compare(Kernel::VERSION, '4.3.0', '<')) {
            return;
        }

        $requestEvent = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request([], [], [], [], [], []),
            HttpKernelInterface::MASTER_REQUEST
        );

        $controllerEvent = new ControllerEvent(
            $this->createMock(HttpKernelInterface::class),
            static function () {
            },
            new Request(),
            HttpKernelInterface::MASTER_REQUEST
        );

        yield 'event.requestType = MASTER_REQUEST' => [
            $requestEvent,
            $controllerEvent,
            new ResponseEvent(
                $this->createMock(HttpKernelInterface::class),
                new Request(),
                HttpKernelInterface::MASTER_REQUEST,
                new Response()
            ),
        ];
    }

    /**
     * @return \Generator<mixed>
     */
    public function handleKernelResponseEventWithSymfonyVersionLowerThan43DataProvider(): \Generator
    {
        if (version_compare(Kernel::VERSION, '4.3.0', '>=')) {
            return;
        }

        $requestEvent = new GetResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request([], [], [], [], [], []),
            HttpKernelInterface::MASTER_REQUEST
        );

        $controllerEvent = new FilterControllerEvent(
            $this->createMock(HttpKernelInterface::class),
            static function () {
            },
            new Request(),
            HttpKernelInterface::MASTER_REQUEST
        );

        yield 'event.requestType = MASTER_REQUEST' => [
            $requestEvent,
            $controllerEvent,
            new FilterResponseEvent(
                $this->createMock(HttpKernelInterface::class),
                new Request(),
                HttpKernelInterface::MASTER_REQUEST,
                new Response()
            ),
        ];
    }

    /**
     * @dataProvider handleKernelTerminateEventWithSymfonyVersionAtLeast43DataProvider
     * @dataProvider handleKernelTerminateEventWithSymfonyVersionLowerThan43DataProvider
     *
     * @param GetResponseEvent|RequestEvent         $requestEvent
     * @param ControllerEvent|FilterControllerEvent $controllerEvent
     * @param ResponseEvent|FilterResponseEvent     $responseEvent
     * @param TerminateEvent|PostResponseEvent      $terminateEvent
     */
    public function testHandleTerminateRequestEvent($requestEvent, $controllerEvent, $responseEvent, $terminateEvent): void
    {
        $transaction = new Transaction(new TransactionContext());
        $transaction->initSpanRecorder();
        $transactionContext = null;

        $this->hub->expects($this->once())
            ->method('startTransaction')
            ->willReturnReference($transaction);

        $this->listener->handleKernelRequestEvent($requestEvent);
        $this->listener->handleKernelControllerEvent($controllerEvent);
        $this->listener->handleKernelResponseEvent($responseEvent);
        $this->listener->handleKernelTerminateEvent($terminateEvent);

        $this->assertCount(4, $transaction->getSpanRecorder()->getSpans());
        $this->assertNotNull($transaction->getEndTimestamp());
    }

    /**
     * @return \Generator<mixed>
     */
    public function handleKernelTerminateEventWithSymfonyVersionAtLeast43DataProvider(): \Generator
    {
        if (version_compare(Kernel::VERSION, '4.3.0', '<')) {
            return;
        }

        $requestEvent = new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request([], [], [], [], [], []),
            HttpKernelInterface::MASTER_REQUEST
        );

        $controllerEvent = new ControllerEvent(
            $this->createMock(HttpKernelInterface::class),
            static function () {
            },
            new Request(),
            HttpKernelInterface::MASTER_REQUEST
        );

        $responseEvent = new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request(),
            HttpKernelInterface::MASTER_REQUEST,
            new Response()
        );

        yield 'event.requestType = MASTER_REQUEST' => [
            $requestEvent,
            $controllerEvent,
            $responseEvent,
            new TerminateEvent(
                $this->createMock(HttpKernelInterface::class),
                new Request(),
                new Response()
            ),
        ];
    }

    /**
     * @return \Generator<mixed>
     */
    public function handleKernelTerminateEventWithSymfonyVersionLowerThan43DataProvider(): \Generator
    {
        if (version_compare(Kernel::VERSION, '4.3.0', '>=')) {
            return;
        }

        $requestEvent = new GetResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request([], [], [], [], [], []),
            HttpKernelInterface::MASTER_REQUEST
        );

        $controllerEvent = new FilterControllerEvent(
            $this->createMock(HttpKernelInterface::class),
            static function () {
            },
            new Request(),
            HttpKernelInterface::MASTER_REQUEST
        );

        $responseEvent = new FilterResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request(),
            HttpKernelInterface::MASTER_REQUEST,
            new Response()
        );

        yield 'event.requestType = MASTER_REQUEST' => [
            $requestEvent,
            $controllerEvent,
            $responseEvent,
            new PostResponseEvent(
                $this->createMock(HttpKernelInterface::class),
                new Request(),
                new Response()
            ),
        ];
    }
}
