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
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
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

    public function testDoesNothingIfTracingIsDisabled(): void
    {
        $this->hub->expects($this->never())
            ->method($this->anything());

        $listener = new TracingSubRequestListener($this->hub, false);
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = new Request();

        $listener->handleKernelRequestEvent(new RequestEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST));
        $listener->collectKernelResponseData(new ResponseEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST, new Response()));
        $listener->handleKernelResponseEvent(new ResponseEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST, new Response()));
        $listener->handleKernelFinishRequestEvent(new FinishRequestEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST));
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
        $transaction = new Transaction(new TransactionContext());
        $request = new Request();
        $subRequestSpan = $this->startSubRequestSpan($transaction, $request);

        $this->listener->handleKernelFinishRequestEvent(new FinishRequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::SUB_REQUEST
        ));

        $this->assertSame(microtime(true), $subRequestSpan->getEndTimestamp());
        $this->assertNull($transaction->getEndTimestamp());
    }

    /**
     * A sub-request can fail before its span is started, e.g. if no route matches it. The current span is the
     * transaction then, which must not be finished or get the status of the sub-request.
     */
    public function testSubRequestWithoutSpanDoesNotChangeTheCurrentSpan(): void
    {
        $transaction = new Transaction(new TransactionContext());

        $this->hub->method('getSpan')
            ->willReturn($transaction);

        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = new Request();

        $this->listener->handleKernelResponseEvent(new ResponseEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST, new Response('', 404)));
        $this->listener->collectKernelResponseData(new ResponseEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST, new Response('', 404)));
        $this->listener->handleKernelFinishRequestEvent(new FinishRequestEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST));

        $this->assertNull($transaction->getEndTimestamp());
        $this->assertNull($transaction->getStatus());
        $this->assertSame([], $transaction->getData());
    }

    public function testNestedSubRequestsFinishTheirOwnSpans(): void
    {
        $transaction = new Transaction(new TransactionContext());
        $kernel = $this->createMock(HttpKernelInterface::class);
        $outerRequest = new Request();
        $innerRequest = new Request();

        $currentSpan = $transaction;

        $this->hub->method('getSpan')
            ->willReturnCallback(static function () use (&$currentSpan): Span {
                return $currentSpan;
            });
        $this->hub->method('setSpan')
            ->willReturnCallback(function (Span $span) use (&$currentSpan): HubInterface {
                $currentSpan = $span;

                return $this->hub;
            });
        $this->listener->handleKernelRequestEvent(new RequestEvent($kernel, $outerRequest, HttpKernelInterface::SUB_REQUEST));
        $outerSpan = $currentSpan;

        $this->listener->handleKernelRequestEvent(new RequestEvent($kernel, $innerRequest, HttpKernelInterface::SUB_REQUEST));
        $innerSpan = $currentSpan;

        $this->assertNotSame($outerSpan, $innerSpan);

        $this->listener->handleKernelFinishRequestEvent(new FinishRequestEvent($kernel, $innerRequest, HttpKernelInterface::SUB_REQUEST));

        $this->assertNotNull($innerSpan->getEndTimestamp());
        $this->assertNull($outerSpan->getEndTimestamp());

        $this->listener->handleKernelFinishRequestEvent(new FinishRequestEvent($kernel, $outerRequest, HttpKernelInterface::SUB_REQUEST));

        $this->assertNotNull($outerSpan->getEndTimestamp());
        $this->assertNull($transaction->getEndTimestamp());
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

    public function testCollectKernelResponseData(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('getOptions')
            ->willReturn(new Options(['data_collection' => []]));

        $parentSpan = new Span();
        $parentSpan->setSampled(true);

        $this->hub->method('getClient')
            ->willReturn($client);

        $request = new Request();
        $span = $this->startSubRequestSpan($parentSpan, $request);

        $response = new Response('{"username":"jane","password":"secret"}', 200, ['Content-Type' => 'application/json']);
        $response->headers->setCookie(Cookie::create('theme', 'dark'));

        $this->listener->collectKernelResponseData(new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::SUB_REQUEST,
            $response
        ));

        $data = $span->getData();
        $this->assertArrayNotHasKey('http.response.body.data', $parentSpan->getData());

        $this->assertSame('application/json', $data['http.response.header.content-type'] ?? null);
        $this->assertSame(['theme=dark'], $data['http.response.header.set-cookie'] ?? null);
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

    public function testCollectKernelResponseDataDoesNothingIfNoSpanWasStartedForTheSubRequest(): void
    {
        $this->hub->expects($this->never())
            ->method('getSpan');

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
        $parentSpan = new Span();
        $request = new Request();
        $span = $this->startSubRequestSpan($parentSpan, $request);

        $this->listener->handleKernelResponseEvent(new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::SUB_REQUEST,
            new Response()
        ));

        $this->assertSame(SpanStatus::ok(), $span->getStatus());
        $this->assertSame(200, $span->getData('http.response.status_code'));
        $this->assertNull($parentSpan->getStatus());
    }

    public function testHandleResponseRequestEventDoesNothingIfNoSpanWasStartedForTheSubRequest(): void
    {
        $this->hub->expects($this->never())
            ->method('getSpan');

        $this->listener->handleKernelResponseEvent(new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request(),
            HttpKernelInterface::SUB_REQUEST,
            new Response()
        ));
    }

    /**
     * Lets the listener start the span of a sub-request below the given span.
     */
    private function startSubRequestSpan(Span $parentSpan, Request $request): Span
    {
        $subRequestSpan = null;

        $this->hub->method('getSpan')
            ->willReturn($parentSpan);
        $this->hub->method('setSpan')
            ->willReturnCallback(function (Span $span) use (&$subRequestSpan): HubInterface {
                $subRequestSpan = $span;

                return $this->hub;
            });

        $this->listener->handleKernelRequestEvent(new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::SUB_REQUEST
        ));

        $this->assertInstanceOf(Span::class, $subRequestSpan);

        return $subRequestSpan;
    }
}
