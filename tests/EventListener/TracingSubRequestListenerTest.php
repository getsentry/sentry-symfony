<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\EventListener;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sentry\ClientInterface;
use Sentry\Options;
use Sentry\SentryBundle\EventListener\TracingSubRequestListener;
use Sentry\State\HubInterface;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanStatus;
use Symfony\Component\HttpFoundation\Cookie;
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
        $request->attributes->set('_controller', [new class {}, 'indexAction']);

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

    /**
     * @dataProvider handleKernelRequestEventCollectsRequestUrlDataProvider
     */
    public function testHandleKernelRequestEventCollectsRequestUrl(Options $options, string $expectedUrl): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())
            ->method('getOptions')
            ->willReturn($options);

        $this->hub->expects($this->once())
            ->method('getClient')
            ->willReturn($client);

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn(new Span());

        $this->hub->expects($this->once())
            ->method('setSpan')
            ->with($this->callback(function (Span $span) use ($expectedUrl): bool {
                $this->assertSame($expectedUrl, $span->getData()['http.url'] ?? null);

                return true;
            }))
            ->willReturnSelf();

        $this->listener->handleKernelRequestEvent(new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('http://www.example.com/path?token=secret&q=a%20b%26c&page=5'),
            HttpKernelInterface::SUB_REQUEST
        ));
    }

    /**
     * @return \Generator<mixed>
     */
    public function handleKernelRequestEventCollectsRequestUrlDataProvider(): \Generator
    {
        yield 'client.options.data_collection IS NULL' => [
            new Options(),
            'http://www.example.com/path?page=5&q=a%20b%26c&token=secret',
        ];

        yield 'client.options.data_collection.url_query_params defaults to denyList' => [
            new Options(['data_collection' => []]),
            'http://www.example.com/path?token=[Filtered]&q=a%20b%26c&page=5',
        ];

        yield 'client.options.data_collection.url_query_params.mode = off' => [
            new Options(['data_collection' => ['url_query_params' => ['mode' => 'off']]]),
            'http://www.example.com/path',
        ];
    }

    public function testHandleKernelRequestEventDoesNothingIfRequestTypeIsMasterRequest(): void
    {
        $this->hub->expects($this->never())
            ->method('getSpan');

        $this->listener->handleKernelRequestEvent(new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request(),
            \defined(HttpKernelInterface::class . '::MAIN_REQUEST') ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::MASTER_REQUEST
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
            \defined(HttpKernelInterface::class . '::MAIN_REQUEST') ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::MASTER_REQUEST
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

    public function testCollectKernelResponseData(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('getOptions')
            ->willReturn(new Options(['data_collection' => []]));

        $span = new Span();
        $span->setSampled(true);

        $this->hub->method('getClient')
            ->willReturn($client);

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($span);

        $response = new Response('{"username":"jane","password":"secret"}', 200, ['Content-Type' => 'application/json']);
        $response->headers->setCookie(Cookie::create('theme', 'dark'));

        $this->listener->collectKernelResponseData(new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request(),
            HttpKernelInterface::SUB_REQUEST,
            $response
        ));

        $data = $span->getData();

        $this->assertSame('application/json', $data['http.response.header.content-type'] ?? null);
        $this->assertSame('dark', $data['http.response.header.set_cookie.theme'] ?? null);
        $this->assertSame('{"username":"jane","password":"[Filtered]"}', $data['http.response.body.data'] ?? null);
    }

    public function testCollectKernelResponseDataDoesNothingIfRequestTypeIsMasterRequest(): void
    {
        $this->hub->expects($this->never())
            ->method('getSpan');

        $this->listener->collectKernelResponseData(new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request(),
            \defined(HttpKernelInterface::class . '::MAIN_REQUEST') ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::MASTER_REQUEST,
            new Response()
        ));
    }

    public function testCollectKernelResponseDataDoesNothingIfNoSpanIsSetOnHub(): void
    {
        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn(null);

        $this->hub->expects($this->never())
            ->method('getClient');

        $this->listener->collectKernelResponseData(new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request(),
            HttpKernelInterface::SUB_REQUEST,
            new Response()
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
