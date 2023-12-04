<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\EventListener;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sentry\ClientInterface;
use Sentry\Options;
use Sentry\SentryBundle\EventListener\TracingRequestListener;
use Sentry\State\HubInterface;
use Sentry\Tracing\DynamicSamplingContext;
use Sentry\Tracing\SpanId;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\TraceId;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Sentry\Tracing\TransactionSource;
use Symfony\Bridge\PhpUnit\ClockMock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Route;

final class TracingRequestListenerTest extends TestCase
{
    /**
     * @var MockObject&HubInterface
     */
    private $hub;

    /**
     * @var TracingRequestListener
     */
    private $listener;

    protected function setUp(): void
    {
        $this->hub = $this->createMock(HubInterface::class);
        $this->listener = new TracingRequestListener($this->hub);
    }

    /**
     * @dataProvider handleKernelRequestEventDataProvider
     */
    public function testHandleKernelRequestEvent(Options $options, Request $request, TransactionContext $expectedTransactionContext): void
    {
        ClockMock::withClockMock(1613493597.010275);

        $transaction = new Transaction(new TransactionContext());

        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->any())
            ->method('getOptions')
            ->willReturn($options);

        $this->hub->expects($this->once())
            ->method('getClient')
            ->willReturn($client);

        $this->hub->expects($this->once())
            ->method('startTransaction')
            ->with($this->callback(function (TransactionContext $context) use ($expectedTransactionContext): bool {
                $this->assertEquals($expectedTransactionContext, $context);

                return true;
            }))
            ->willReturn($transaction);

        $this->hub->expects($this->once())
            ->method('setSpan')
            ->with($transaction);

        $this->listener->handleKernelRequestEvent(new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            defined(HttpKernelInterface::class . '::MAIN_REQUEST') ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::MASTER_REQUEST
        ));
    }

    /**
     * @return \Generator<mixed>
     */
    public function handleKernelRequestEventDataProvider(): \Generator
    {
        $samplingContext = DynamicSamplingContext::fromHeader('');
        $samplingContext->freeze();

        $transactionContext = new TransactionContext();
        $transactionContext->setTraceId(new TraceId('566e3688a61d4bc888951642d6f14a19'));
        $transactionContext->setParentSpanId(new SpanId('566e3688a61d4bc8'));
        $transactionContext->setParentSampled(true);
        $transactionContext->setName('GET http://www.example.com/');
        $transactionContext->setSource(TransactionSource::url());
        $transactionContext->setOp('http.server');
        $transactionContext->setStartTimestamp(1613493597.010275);
        $transactionContext->setData([
            'net.host.port' => '80',
            'http.request.method' => 'GET',
            'http.url' => 'http://www.example.com/',
            'http.flavor' => '1.1',
            'route' => '<unknown>',
            'net.host.name' => 'www.example.com',
        ]);
        $transactionContext->getMetadata()->setDynamicSamplingContext($samplingContext);

        yield 'request.headers.sentry-trace EXISTS' => [
            new Options(),
            Request::create(
                'http://www.example.com',
                'GET',
                [],
                [],
                [],
                [
                    'REQUEST_TIME_FLOAT' => 1613493597.010275,
                    'HTTP_sentry-trace' => '566e3688a61d4bc888951642d6f14a19-566e3688a61d4bc8-1',
                ]
            ),
            $transactionContext,
        ];

        $samplingContext = DynamicSamplingContext::fromHeader('sentry-trace_id=566e3688a61d4bc888951642d6f14a19,sentry-public_key=public,sentry-sample_rate=1');
        $samplingContext->freeze();

        $transactionContext = new TransactionContext();
        $transactionContext->setTraceId(new TraceId('566e3688a61d4bc888951642d6f14a19'));
        $transactionContext->setParentSpanId(new SpanId('566e3688a61d4bc8'));
        $transactionContext->setParentSampled(true);
        $transactionContext->setName('GET http://www.example.com/');
        $transactionContext->setSource(TransactionSource::url());
        $transactionContext->setOp('http.server');
        $transactionContext->setStartTimestamp(1613493597.010275);
        $transactionContext->setData([
            'net.host.port' => '80',
            'http.request.method' => 'GET',
            'http.url' => 'http://www.example.com/',
            'http.flavor' => '1.1',
            'route' => '<unknown>',
            'net.host.name' => 'www.example.com',
        ]);
        $transactionContext->getMetadata()->setDynamicSamplingContext($samplingContext);

        yield 'request.headers.sentry-trace and headers.baggage EXISTS' => [
            new Options(),
            Request::create(
                'http://www.example.com',
                'GET',
                [],
                [],
                [],
                [
                    'REQUEST_TIME_FLOAT' => 1613493597.010275,
                    'HTTP_sentry-trace' => '566e3688a61d4bc888951642d6f14a19-566e3688a61d4bc8-1',
                    'HTTP_baggage' => 'sentry-trace_id=566e3688a61d4bc888951642d6f14a19,sentry-public_key=public,sentry-sample_rate=1',
                ]
            ),
            $transactionContext,
        ];

        $transactionContext = new TransactionContext();
        $transactionContext->setName('GET http://www.example.com/');
        $transactionContext->setSource(TransactionSource::url());
        $transactionContext->setOp('http.server');
        $transactionContext->setStartTimestamp(1613493597.010275);
        $transactionContext->setData([
            'net.host.port' => '80',
            'http.request.method' => 'GET',
            'http.url' => 'http://www.example.com/',
            'http.flavor' => '1.1',
            'route' => '<unknown>',
            'net.host.name' => 'www.example.com',
        ]);

        $request = Request::create('http://www.example.com');
        $request->server->remove('REQUEST_TIME_FLOAT');

        yield 'request.server.REQUEST_TIME_FLOAT NOT EXISTS' => [
            new Options(),
            $request,
            $transactionContext,
        ];

        $transactionContext = new TransactionContext();
        $transactionContext->setName('GET http://127.0.0.1/');
        $transactionContext->setSource(TransactionSource::url());
        $transactionContext->setOp('http.server');
        $transactionContext->setStartTimestamp(1613493597.010275);
        $transactionContext->setData([
            'net.host.port' => '80',
            'http.request.method' => 'GET',
            'http.url' => 'http://127.0.0.1/',
            'http.flavor' => '1.1',
            'route' => '<unknown>',
            'net.host.ip' => '127.0.0.1',
        ]);

        yield 'request.server.HOST IS IPV4' => [
            new Options(),
            Request::create(
                'http://127.0.0.1',
                'GET',
                [],
                [],
                [],
                ['REQUEST_TIME_FLOAT' => 1613493597.010275]
            ),
            $transactionContext,
        ];

        $request = Request::create('http://www.example.com/path');
        $request->server->set('REQUEST_TIME_FLOAT', 1613493597.010275);
        $request->attributes->set('_route', 'app_homepage');

        $transactionContext = new TransactionContext();
        $transactionContext->setName('GET app_homepage');
        $transactionContext->setSource(TransactionSource::route());
        $transactionContext->setOp('http.server');
        $transactionContext->setStartTimestamp(1613493597.010275);
        $transactionContext->setData([
            'net.host.port' => '80',
            'http.request.method' => 'GET',
            'http.url' => 'http://www.example.com/path',
            'http.flavor' => '1.1',
            'route' => 'app_homepage',
            'net.host.name' => 'www.example.com',
        ]);

        yield 'request.attributes.route IS STRING' => [
            new Options(),
            $request,
            $transactionContext,
        ];

        $request = Request::create('http://www.example.com/path');
        $request->server->set('REQUEST_TIME_FLOAT', 1613493597.010275);
        $request->attributes->set('_route', new Route('/path'));

        $transactionContext = new TransactionContext();
        $transactionContext->setName('GET http://www.example.com/path');
        $transactionContext->setSource(TransactionSource::url());
        $transactionContext->setOp('http.server');
        $transactionContext->setStartTimestamp(1613493597.010275);
        $transactionContext->setData([
            'net.host.port' => '80',
            'http.request.method' => 'GET',
            'http.url' => 'http://www.example.com/path',
            'http.flavor' => '1.1',
            'route' => '/path',
            'net.host.name' => 'www.example.com',
        ]);

        yield 'request.attributes.route IS INSTANCEOF Symfony\Component\Routing\Route' => [
            new Options(),
            $request,
            $transactionContext,
        ];

        $request = Request::create('http://www.example.com/');
        $request->server->set('REQUEST_TIME_FLOAT', 1613493597.010275);
        $request->attributes->set('_controller', 'App\\Controller::indexAction');

        $transactionContext = new TransactionContext();
        $transactionContext->setName('GET http://www.example.com/');
        $transactionContext->setSource(TransactionSource::url());
        $transactionContext->setOp('http.server');
        $transactionContext->setStartTimestamp(1613493597.010275);
        $transactionContext->setData([
            'net.host.port' => '80',
            'http.request.method' => 'GET',
            'http.url' => 'http://www.example.com/',
            'http.flavor' => '1.1',
            'route' => 'App\\Controller::indexAction',
            'net.host.name' => 'www.example.com',
        ]);

        yield 'request.attributes._controller IS STRING' => [
            new Options(),
            $request,
            $transactionContext,
        ];

        $request = Request::create('http://www.example.com/');
        $request->server->set('REQUEST_TIME_FLOAT', 1613493597.010275);
        $request->attributes->set('_controller', ['App\\Controller', 'indexAction']);

        $transactionContext = new TransactionContext();
        $transactionContext->setName('GET http://www.example.com/');
        $transactionContext->setSource(TransactionSource::url());
        $transactionContext->setOp('http.server');
        $transactionContext->setStartTimestamp(1613493597.010275);
        $transactionContext->setData([
            'net.host.port' => '80',
            'http.request.method' => 'GET',
            'http.url' => 'http://www.example.com/',
            'http.flavor' => '1.1',
            'route' => 'App\\Controller::indexAction',
            'net.host.name' => 'www.example.com',
        ]);

        yield 'request.attributes._controller IS CALLABLE (1)' => [
            new Options(),
            $request,
            $transactionContext,
        ];

        $request = Request::create('http://www.example.com/');
        $request->server->set('REQUEST_TIME_FLOAT', 1613493597.010275);
        $request->attributes->set('_controller', [new class() {}, 'indexAction']);

        $transactionContext = new TransactionContext();
        $transactionContext->setName('GET http://www.example.com/');
        $transactionContext->setSource(TransactionSource::url());
        $transactionContext->setOp('http.server');
        $transactionContext->setStartTimestamp(1613493597.010275);
        $transactionContext->setData([
            'net.host.port' => '80',
            'http.request.method' => 'GET',
            'http.url' => 'http://www.example.com/',
            'http.flavor' => '1.1',
            'route' => 'class@anonymous::indexAction',
            'net.host.name' => 'www.example.com',
        ]);

        yield 'request.attributes._controller IS CALLABLE (2)' => [
            new Options(),
            $request,
            $transactionContext,
        ];

        $request = Request::create('http://www.example.com/');
        $request->server->set('REQUEST_TIME_FLOAT', 1613493597.010275);
        $request->attributes->set('_controller', [10]);

        $transactionContext = new TransactionContext();
        $transactionContext->setName('GET http://www.example.com/');
        $transactionContext->setSource(TransactionSource::url());
        $transactionContext->setOp('http.server');
        $transactionContext->setStartTimestamp(1613493597.010275);
        $transactionContext->setData([
            'net.host.port' => '80',
            'http.request.method' => 'GET',
            'http.url' => 'http://www.example.com/',
            'http.flavor' => '1.1',
            'route' => '<unknown>',
            'net.host.name' => 'www.example.com',
        ]);

        yield 'request.attributes._controller IS ARRAY and NOT VALID CALLABLE' => [
            new Options(),
            $request,
            $transactionContext,
        ];

        $request = Request::create('http://www.example.com/');
        $request->server->set('REQUEST_TIME_FLOAT', 1613493597.010275);
        $request->attributes->set('_controller', [10]);

        $transactionContext = new TransactionContext();
        $transactionContext->setName('GET http://www.example.com/');
        $transactionContext->setSource(TransactionSource::url());
        $transactionContext->setOp('http.server');
        $transactionContext->setStartTimestamp(1613493597.010275);
        $transactionContext->setData([
            'net.host.port' => '80',
            'http.request.method' => 'GET',
            'http.url' => 'http://www.example.com/',
            'http.flavor' => '1.1',
            'route' => '<unknown>',
            'net.host.name' => 'www.example.com',
            'net.peer.ip' => '127.0.0.1',
        ]);

        yield 'request.server.REMOTE_ADDR EXISTS and client.options.send_default_pii = TRUE' => [
            new Options(['send_default_pii' => true]),
            $request,
            $transactionContext,
        ];

        $request = Request::createFromGlobals();
        $request->server->set('REQUEST_TIME_FLOAT', 1613493597.010275);

        $transactionContext = new TransactionContext();
        $transactionContext->setName('GET http://:/');
        $transactionContext->setSource(TransactionSource::url());
        $transactionContext->setOp('http.server');
        $transactionContext->setStartTimestamp(1613493597.010275);
        $transactionContext->setData([
            'net.host.port' => '',
            'http.request.method' => 'GET',
            'http.url' => 'http://:/',
            'route' => '<unknown>',
            'net.host.name' => '',
        ]);

        yield 'request.server.SERVER_PROTOCOL NOT EXISTS' => [
            new Options(),
            $request,
            $transactionContext,
        ];
    }

    public function testHandleKernelRequestEventDoesNothingIfRequestTypeIsSubRequest(): void
    {
        $this->hub->expects($this->never())
            ->method('startTransaction');

        $this->listener->handleKernelRequestEvent(new RequestEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request(),
            HttpKernelInterface::SUB_REQUEST
        ));
    }

    public function testHandleResponseRequestEvent(): void
    {
        $transaction = new Transaction(new TransactionContext());

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($transaction);

        $this->listener->handleKernelResponseEvent(new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request(),
            defined(HttpKernelInterface::class . '::MAIN_REQUEST') ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::MASTER_REQUEST,
            new Response()
        ));

        $this->assertSame(SpanStatus::ok(), $transaction->getStatus());
        $this->assertSame(['http.status_code' => '200'], $transaction->getTags());
    }

    public function testHandleResponseRequestEventDoesNothingIfNoTransactionIsSetOnHub(): void
    {
        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn(null);

        $this->listener->handleKernelResponseEvent(new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request(),
            defined(HttpKernelInterface::class . '::MAIN_REQUEST') ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::MASTER_REQUEST,
            new Response()
        ));
    }

    /**
     * @group time-sensitive
     */
    public function testHandleKernelTerminateEvent(): void
    {
        $transaction = new Transaction(new TransactionContext());

        $this->hub->expects($this->once())
            ->method('getTransaction')
            ->willReturn($transaction);

        $this->listener->handleKernelTerminateEvent(new TerminateEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request(),
            new Response()
        ));

        $this->assertSame(microtime(true), $transaction->getEndTimestamp());
    }

    public function testHandleKernelTerminateEventDoesNothingIfNoTransactionIsSetOnHub(): void
    {
        $this->hub->expects($this->once())
            ->method('getTransaction')
            ->willReturn(null);

        $this->listener->handleKernelTerminateEvent(new TerminateEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request(),
            new Response()
        ));
    }
}
