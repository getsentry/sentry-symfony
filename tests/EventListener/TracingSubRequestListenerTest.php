<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\EventListener;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\EventListener\TracingSubRequestListener;
use Sentry\State\HubInterface;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanStatus;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class TracingSubRequestListenerTest extends TestCase
{
    /**
     * @var MockObject&HubInterface
     */
    private $hub;

    /**
     * @var TracingSubRequestListener
     */
    private $listener;

    protected function setUp(): void
    {
        $this->hub = $this->createMock(HubInterface::class);
        $this->listener = new TracingSubRequestListener($this->hub);
    }

    /**
     * @dataProvider handleKernelRequestEventDataProvider
     */
    public function testHandleKernelRequestEvent(Request $request, Span $expectedSpan): void
    {
        $span = new Span();

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($span);

        $this->hub->expects($this->once())
            ->method('setSpan')
            ->with($this->callback(function (Span $span) use ($expectedSpan): bool {
                $this->assertSame($expectedSpan->getOp(), $span->getOp());
                $this->assertSame($expectedSpan->getDescription(), $span->getDescription());
                $this->assertSame($expectedSpan->getTags(), $span->getTags());

                return true;
            }))
            ->willReturnSelf();

        $this->listener->handleKernelRequestEvent(new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::SUB_REQUEST
        ));
    }

    /**
     * @return \Generator<mixed>
     */
    public function handleKernelRequestEventDataProvider(): \Generator
    {
        $request = Request::create('http://www.example.com/path');
        $request->attributes->set('_controller', 'App\\Controller::indexAction');

        $span = new Span();
        $span->setOp('http.server');
        $span->setDescription('GET http://www.example.com/path');
        $span->setData([
            'http.request.method' => 'GET',
            'http.url' => 'http://www.example.com/path',
            'route' => 'App\\Controller::indexAction',
        ]);

        yield 'request.attributes.controller IS STRING' => [
            $request,
            $span,
        ];

        $request = Request::create('http://www.example.com/');
        $request->attributes->set('_controller', ['App\\Controller', 'indexAction']);

        $span = new Span();
        $span->setOp('http.server');
        $span->setDescription('GET http://www.example.com/');
        $span->setData([
            'http.request.method' => 'GET',
            'http.url' => 'http://www.example.com/',
            'route' => 'App\\Controller::indexAction',
        ]);

        yield 'request.attributes.controller IS CALLABLE (1)' => [
            $request,
            $span,
        ];

        $request = Request::create('http://www.example.com/');
        $request->attributes->set('_controller', [new class() {}, 'indexAction']);

        $span = new Span();
        $span->setOp('http.server');
        $span->setDescription('GET http://www.example.com/');
        $span->setData([
            'http.request.method' => 'GET',
            'http.url' => 'http://www.example.com/',
            'route' => 'class@anonymous::indexAction',
        ]);

        yield 'request.attributes.controller IS CALLABLE (2)' => [
            $request,
            $span,
        ];

        $request = Request::create('http://www.example.com/');
        $request->attributes->set('_controller', [10]);

        $span = new Span();
        $span->setOp('http.server');
        $span->setDescription('GET http://www.example.com/');
        $span->setData([
            'http.request.method' => 'GET',
            'http.url' => 'http://www.example.com/',
            'route' => '<unknown>',
        ]);

        yield 'request.attributes.controller IS ARRAY and NOT VALID CALLABLE' => [
            $request,
            $span,
        ];
    }

    public function testHandleKernelRequestEventDoesNothingIfRequestTypeIsMasterRequest(): void
    {
        $this->hub->expects($this->never())
            ->method('getSpan');

        $this->listener->handleKernelRequestEvent(new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request(),
            defined(HttpKernelInterface::class . '::MAIN_REQUEST') ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::MASTER_REQUEST
        ));
    }

    public function testHandleKernelRequestEventDoesNothingIfNoSpanIsSetOnHub(): void
    {
        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn(null);

        $this->listener->handleKernelRequestEvent(new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request(),
            HttpKernelInterface::SUB_REQUEST
        ));
    }

    /**
     * @group time-sensitive
     */
    public function testHandleKernelFinishRequestEvent(): void
    {
        $span = new Span();

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($span);

        $this->listener->handleKernelFinishRequestEvent(new FinishRequestEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request(),
            HttpKernelInterface::SUB_REQUEST
        ));

        $this->assertSame(microtime(true), $span->getEndTimestamp());
    }

    public function testHandleKernelFinishRequestEventDoesNothingIfRequestTypeIsMasterRequest(): void
    {
        $this->hub->expects($this->never())
            ->method('getSpan');

        $this->listener->handleKernelFinishRequestEvent(new FinishRequestEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request(),
            defined(HttpKernelInterface::class . '::MAIN_REQUEST') ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::MASTER_REQUEST
        ));
    }

    public function testHandleKernelFinishRequestEventDoesNothingIfNoSpanIsSetOnHub(): void
    {
        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn(null);

        $this->listener->handleKernelFinishRequestEvent(new FinishRequestEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request(),
            HttpKernelInterface::SUB_REQUEST
        ));
    }

    public function testHandleResponseRequestEvent(): void
    {
        $span = new Span();

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($span);

        $this->listener->handleKernelResponseEvent(new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request(),
            HttpKernelInterface::SUB_REQUEST,
            new Response()
        ));

        $this->assertSame(SpanStatus::ok(), $span->getStatus());
        $this->assertSame(['http.status_code' => '200'], $span->getTags());
    }

    public function testHandleResponseRequestEventDoesNothingIfNoTransactionIsSetOnHub(): void
    {
        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn(null);

        $this->listener->handleKernelResponseEvent(new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request(),
            HttpKernelInterface::SUB_REQUEST,
            new Response()
        ));
    }
}
