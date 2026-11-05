<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\EventListener;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sentry\ClientInterface;
use Sentry\Options;
use Sentry\SentryBundle\EventListener\TracingRequestListener;
use Sentry\SentryBundle\Integration\RequestFetcher;
use Sentry\State\HubInterface;
use Sentry\Tracing\DynamicSamplingContext;
use Sentry\Tracing\SpanId;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\TraceId;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Sentry\Tracing\TransactionSource;
use Symfony\Bridge\PhpUnit\ClockMock;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
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
                if (null === $expectedTransactionContext->getTraceId() && null !== $context->getTraceId()) {
                    $expectedTransactionContext->setTraceId($context->getTraceId());
                }

                // This value is random when the metadata is constructed, thus we set it to a fixed expected value since we don't care for the value here
                $context->getMetadata()->setSampleRand(0.1337);

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
            \defined(HttpKernelInterface::class . '::MAIN_REQUEST') ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::MASTER_REQUEST
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
        $transactionContext->setOrigin('auto.http.server');
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
        $transactionContext->getMetadata()->setSampleRand(0.1337);

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
        $transactionContext->setOrigin('auto.http.server');
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
        $transactionContext->getMetadata()->setParentSamplingRate(1.0);
        $transactionContext->getMetadata()->setSampleRand(0.1337);

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
        $transactionContext->setOrigin('auto.http.server');
        $transactionContext->setStartTimestamp(1613493597.010275);
        $transactionContext->setData([
            'net.host.port' => '80',
            'http.request.method' => 'GET',
            'http.url' => 'http://www.example.com/',
            'http.flavor' => '1.1',
            'route' => '<unknown>',
            'net.host.name' => 'www.example.com',
        ]);
        $transactionContext->getMetadata()->setSampleRand(0.1337);

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
        $transactionContext->setOrigin('auto.http.server');
        $transactionContext->setStartTimestamp(1613493597.010275);
        $transactionContext->setData([
            'net.host.port' => '80',
            'http.request.method' => 'GET',
            'http.url' => 'http://127.0.0.1/',
            'http.flavor' => '1.1',
            'route' => '<unknown>',
            'net.host.ip' => '127.0.0.1',
        ]);
        $transactionContext->getMetadata()->setSampleRand(0.1337);

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
        $transactionContext->setOrigin('auto.http.server');
        $transactionContext->setStartTimestamp(1613493597.010275);
        $transactionContext->setData([
            'net.host.port' => '80',
            'http.request.method' => 'GET',
            'http.url' => 'http://www.example.com/path',
            'http.flavor' => '1.1',
            'route' => 'app_homepage',
            'net.host.name' => 'www.example.com',
        ]);
        $transactionContext->getMetadata()->setSampleRand(0.1337);

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
        $transactionContext->setOrigin('auto.http.server');
        $transactionContext->setStartTimestamp(1613493597.010275);
        $transactionContext->setData([
            'net.host.port' => '80',
            'http.request.method' => 'GET',
            'http.url' => 'http://www.example.com/path',
            'http.flavor' => '1.1',
            'route' => '/path',
            'net.host.name' => 'www.example.com',
        ]);
        $transactionContext->getMetadata()->setSampleRand(0.1337);

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
        $transactionContext->setOrigin('auto.http.server');
        $transactionContext->setStartTimestamp(1613493597.010275);
        $transactionContext->setData([
            'net.host.port' => '80',
            'http.request.method' => 'GET',
            'http.url' => 'http://www.example.com/',
            'http.flavor' => '1.1',
            'route' => 'App\\Controller::indexAction',
            'net.host.name' => 'www.example.com',
        ]);
        $transactionContext->getMetadata()->setSampleRand(0.1337);

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
        $transactionContext->setOrigin('auto.http.server');
        $transactionContext->setStartTimestamp(1613493597.010275);
        $transactionContext->setData([
            'net.host.port' => '80',
            'http.request.method' => 'GET',
            'http.url' => 'http://www.example.com/',
            'http.flavor' => '1.1',
            'route' => 'App\\Controller::indexAction',
            'net.host.name' => 'www.example.com',
        ]);
        $transactionContext->getMetadata()->setSampleRand(0.1337);

        yield 'request.attributes._controller IS CALLABLE (1)' => [
            new Options(),
            $request,
            $transactionContext,
        ];

        $request = Request::create('http://www.example.com/');
        $request->server->set('REQUEST_TIME_FLOAT', 1613493597.010275);
        $request->attributes->set('_controller', [new class {
        }, 'indexAction']);

        $transactionContext = new TransactionContext();
        $transactionContext->setName('GET http://www.example.com/');
        $transactionContext->setSource(TransactionSource::url());
        $transactionContext->setOp('http.server');
        $transactionContext->setOrigin('auto.http.server');
        $transactionContext->setStartTimestamp(1613493597.010275);
        $transactionContext->setData([
            'net.host.port' => '80',
            'http.request.method' => 'GET',
            'http.url' => 'http://www.example.com/',
            'http.flavor' => '1.1',
            'route' => 'class@anonymous::indexAction',
            'net.host.name' => 'www.example.com',
        ]);
        $transactionContext->getMetadata()->setSampleRand(0.1337);

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
        $transactionContext->setOrigin('auto.http.server');
        $transactionContext->setStartTimestamp(1613493597.010275);
        $transactionContext->setData([
            'net.host.port' => '80',
            'http.request.method' => 'GET',
            'http.url' => 'http://www.example.com/',
            'http.flavor' => '1.1',
            'route' => '<unknown>',
            'net.host.name' => 'www.example.com',
        ]);
        $transactionContext->getMetadata()->setSampleRand(0.1337);

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
        $transactionContext->setOrigin('auto.http.server');
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
        $transactionContext->getMetadata()->setSampleRand(0.1337);

        yield 'request.server.REMOTE_ADDR EXISTS and client.options.send_default_pii = TRUE' => [
            new Options(['send_default_pii' => true]),
            $request,
            $transactionContext,
        ];

        $request = Request::create('http://www.example.com/');
        $request->server->set('REQUEST_TIME_FLOAT', 1613493597.010275);

        $transactionContext = new TransactionContext();
        $transactionContext->setName('GET http://www.example.com/');
        $transactionContext->setSource(TransactionSource::url());
        $transactionContext->setOp('http.server');
        $transactionContext->setOrigin('auto.http.server');
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
        $transactionContext->getMetadata()->setSampleRand(0.1337);

        yield 'request.server.REMOTE_ADDR EXISTS and client.options.data_collection.user_info defaults to TRUE' => [
            new Options(['send_default_pii' => false, 'data_collection' => []]),
            $request,
            $transactionContext,
        ];

        $request = Request::create('http://www.example.com/');
        $request->server->set('REQUEST_TIME_FLOAT', 1613493597.010275);

        $transactionContext = new TransactionContext();
        $transactionContext->setName('GET http://www.example.com/');
        $transactionContext->setSource(TransactionSource::url());
        $transactionContext->setOp('http.server');
        $transactionContext->setOrigin('auto.http.server');
        $transactionContext->setStartTimestamp(1613493597.010275);
        $transactionContext->setData([
            'net.host.port' => '80',
            'http.request.method' => 'GET',
            'http.url' => 'http://www.example.com/',
            'http.flavor' => '1.1',
            'route' => '<unknown>',
            'net.host.name' => 'www.example.com',
        ]);
        $transactionContext->getMetadata()->setSampleRand(0.1337);

        yield 'request.server.REMOTE_ADDR EXISTS and client.options.data_collection.user_info = FALSE' => [
            new Options(['send_default_pii' => true, 'data_collection' => ['user_info' => false]]),
            $request,
            $transactionContext,
        ];

        $request = Request::create('http://www.example.com/?token=secret&q=a%20b%26c&page=5');
        $request->server->set('REQUEST_TIME_FLOAT', 1613493597.010275);

        $transactionContext = new TransactionContext();
        $transactionContext->setName('GET http://www.example.com/');
        $transactionContext->setSource(TransactionSource::url());
        $transactionContext->setOp('http.server');
        $transactionContext->setOrigin('auto.http.server');
        $transactionContext->setStartTimestamp(1613493597.010275);
        $transactionContext->setData([
            'net.host.port' => '80',
            'http.request.method' => 'GET',
            'http.url' => 'http://www.example.com/?page=5&q=a%20b%26c&token=secret',
            'http.flavor' => '1.1',
            'route' => '<unknown>',
            'net.host.name' => 'www.example.com',
        ]);
        $transactionContext->getMetadata()->setSampleRand(0.1337);

        yield 'request.server.QUERY_STRING EXISTS and client.options.data_collection IS NULL' => [
            new Options(),
            $request,
            $transactionContext,
        ];

        $request = Request::create('http://www.example.com/?token=secret&q=a%20b%26c&page=5');
        $request->server->set('REQUEST_TIME_FLOAT', 1613493597.010275);

        $transactionContext = new TransactionContext();
        $transactionContext->setName('GET http://www.example.com/');
        $transactionContext->setSource(TransactionSource::url());
        $transactionContext->setOp('http.server');
        $transactionContext->setOrigin('auto.http.server');
        $transactionContext->setStartTimestamp(1613493597.010275);
        $transactionContext->setData([
            'net.host.port' => '80',
            'http.request.method' => 'GET',
            'http.url' => 'http://www.example.com/?token=[Filtered]&q=a%20b%26c&page=5',
            'http.flavor' => '1.1',
            'route' => '<unknown>',
            'net.host.name' => 'www.example.com',
            'net.peer.ip' => '127.0.0.1',
        ]);
        $transactionContext->getMetadata()->setSampleRand(0.1337);

        yield 'request.server.QUERY_STRING EXISTS and client.options.data_collection.url_query_params defaults to denyList' => [
            new Options(['data_collection' => []]),
            $request,
            $transactionContext,
        ];

        $request = Request::create('http://www.example.com/?token=secret&q=a%20b%26c&page=5');
        $request->server->set('REQUEST_TIME_FLOAT', 1613493597.010275);

        $transactionContext = new TransactionContext();
        $transactionContext->setName('GET http://www.example.com/');
        $transactionContext->setSource(TransactionSource::url());
        $transactionContext->setOp('http.server');
        $transactionContext->setOrigin('auto.http.server');
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
        $transactionContext->getMetadata()->setSampleRand(0.1337);

        yield 'request.server.QUERY_STRING EXISTS and client.options.data_collection.url_query_params.mode = off' => [
            new Options(['data_collection' => ['url_query_params' => ['mode' => 'off']]]),
            $request,
            $transactionContext,
        ];

        $request = Request::createFromGlobals();
        $request->server->set('REQUEST_TIME_FLOAT', 1613493597.010275);

        $transactionContext = new TransactionContext();
        $transactionContext->setName('GET http://:/');
        $transactionContext->setSource(TransactionSource::url());
        $transactionContext->setOp('http.server');
        $transactionContext->setOrigin('auto.http.server');
        $transactionContext->setStartTimestamp(1613493597.010275);
        $transactionContext->setData([
            'net.host.port' => '',
            'http.request.method' => 'GET',
            'http.url' => 'http://:/',
            'route' => '<unknown>',
            'net.host.name' => '',
        ]);
        $transactionContext->getMetadata()->setSampleRand(0.1337);

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

    /**
     * @param array<string, mixed> $expectedData
     *
     * @dataProvider collectKernelResponseDataDataProvider
     */
    public function testCollectKernelResponseData(Options $options, array $expectedData): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('getOptions')
            ->willReturn($options);

        $transactionContext = new TransactionContext();
        $transactionContext->setSampled(true);

        $transaction = new Transaction($transactionContext);

        $this->hub->method('getClient')
            ->willReturn($client);

        $this->hub->expects($this->once())
            ->method('getTransaction')
            ->willReturn($transaction);

        $response = new Response('{"username":"jane","password":"secret"}', 200, [
            'Content-Type' => 'application/json',
            'X-Auth-Token' => 'foo',
            'X-Served-By' => ['web-1', 'web-2'],
        ]);
        $response->headers->setCookie(Cookie::create('session_id', 'abc'));
        $response->headers->setCookie(Cookie::create('theme', 'dark'));

        $this->listener->collectKernelResponseData(new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request(),
            \defined(HttpKernelInterface::class . '::MAIN_REQUEST') ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::MASTER_REQUEST,
            $response
        ));

        $data = $transaction->getData();

        // The date header changes with every response
        unset($data['http.response.header.date']);

        $this->assertEquals($expectedData, $data);
    }

    /**
     * @return \Generator<mixed>
     */
    public function collectKernelResponseDataDataProvider(): \Generator
    {
        yield 'The legacy options do not collect the response' => [
            new Options(['send_default_pii' => true]),
            [],
        ];

        yield 'The data collection options collect and filter the headers, cookies and body' => [
            new Options(['data_collection' => []]),
            [
                'http.response.header.content-type' => 'application/json',
                'http.response.header.x-auth-token' => '[Filtered]',
                'http.response.header.cache-control' => 'no-cache, private',
                'http.response.header.x-served-by' => 'web-1, web-2',
                'http.response.header.set-cookie' => ['session_id=[Filtered]', 'theme=dark'],
                'http.response.body.data' => '{"username":"jane","password":"[Filtered]"}',
            ],
        ];

        yield 'The body is not collected if outgoing response bodies are disabled' => [
            new Options(['data_collection' => ['http_bodies' => ['incomingRequest']]]),
            [
                'http.response.header.content-type' => 'application/json',
                'http.response.header.x-auth-token' => '[Filtered]',
                'http.response.header.cache-control' => 'no-cache, private',
                'http.response.header.x-served-by' => 'web-1, web-2',
                'http.response.header.set-cookie' => ['session_id=[Filtered]', 'theme=dark'],
            ],
        ];
    }

    /**
     * @dataProvider collectKernelResponseDataWithUnavailableContentDataProvider
     */
    public function testCollectKernelResponseDataWithUnavailableContent(Options $options, Response $response, ?string $expectedBody): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('getOptions')
            ->willReturn($options);

        $transactionContext = new TransactionContext();
        $transactionContext->setSampled(true);

        $transaction = new Transaction($transactionContext);

        $this->hub->method('getClient')
            ->willReturn($client);

        $this->hub->expects($this->once())
            ->method('getTransaction')
            ->willReturn($transaction);

        $this->listener->collectKernelResponseData(new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request(),
            \defined(HttpKernelInterface::class . '::MAIN_REQUEST') ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::MASTER_REQUEST,
            $response
        ));

        $this->assertSame($expectedBody, $transaction->getData()['http.response.body.data'] ?? null);
    }

    /**
     * @return \Generator<mixed>
     */
    public function collectKernelResponseDataWithUnavailableContentDataProvider(): \Generator
    {
        yield 'The body of a streamed response is filtered, as it cannot be parsed' => [
            new Options(['data_collection' => []]),
            new StreamedResponse(static function (): void {}, 200, ['Content-Type' => 'application/json']),
            '[Filtered]',
        ];

        yield 'The body of a file response is filtered, as it cannot be parsed' => [
            new Options(['data_collection' => []]),
            new BinaryFileResponse(__FILE__),
            '[Filtered]',
        ];

        yield 'The body is not collected if outgoing response bodies are disabled' => [
            new Options(['data_collection' => ['http_bodies' => ['incomingRequest']]]),
            new StreamedResponse(static function (): void {}, 200, ['Content-Type' => 'application/json']),
            null,
        ];

        yield 'The legacy options do not collect the body' => [
            new Options(['send_default_pii' => true]),
            new StreamedResponse(static function (): void {}, 200, ['Content-Type' => 'application/json']),
            null,
        ];
    }

    public function testCollectKernelResponseDataDoesNothingIfTransactionIsNotSampled(): void
    {
        $transactionContext = new TransactionContext();
        $transactionContext->setSampled(false);

        $transaction = new Transaction($transactionContext);

        $this->hub->expects($this->never())
            ->method('getClient');

        $this->hub->expects($this->once())
            ->method('getTransaction')
            ->willReturn($transaction);

        $this->listener->collectKernelResponseData(new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request(),
            \defined(HttpKernelInterface::class . '::MAIN_REQUEST') ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::MASTER_REQUEST,
            new Response('foo')
        ));

        $this->assertSame([], $transaction->getData());
    }

    public function testCollectKernelResponseDataIgnoresSubRequests(): void
    {
        $this->hub->expects($this->never())
            ->method('getTransaction');

        $this->listener->collectKernelResponseData(new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request(),
            HttpKernelInterface::SUB_REQUEST,
            new Response('foo')
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
            \defined(HttpKernelInterface::class . '::MAIN_REQUEST') ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::MASTER_REQUEST,
            new Response()
        ));

        $this->assertSame(SpanStatus::ok(), $transaction->getStatus());
        $this->assertSame(200, $transaction->getData('http.response.status_code'));
    }

    public function testHandleResponseRequestEventDoesNothingIfNoTransactionIsSetOnHub(): void
    {
        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn(null);

        $this->listener->handleKernelResponseEvent(new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request(),
            \defined(HttpKernelInterface::class . '::MAIN_REQUEST') ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::MASTER_REQUEST,
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

    public function testHandleKernelTerminateEventClearsRequestFetcherIfNoTransactionIsSetOnHub(): void
    {
        $requestStack = $this->createMock(RequestStack::class);
        $httpMessageFactory = $this->createMock(HttpMessageFactoryInterface::class);
        $requestFetcher = new RequestFetcher($requestStack, $httpMessageFactory);
        $listener = new TracingRequestListener($this->hub, $requestFetcher);

        $requestFetcher->setRequest(Request::create('https://www.example.com/manual'));

        $this->hub->expects($this->once())
            ->method('getTransaction')
            ->willReturn(null);

        $requestStack->expects($this->once())
            ->method('getCurrentRequest')
            ->willReturn(null);

        $httpMessageFactory->expects($this->never())
            ->method('createRequest');

        $listener->handleKernelTerminateEvent(new TerminateEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request(),
            new Response()
        ));

        $this->assertNull($requestFetcher->fetchRequest());
    }

    /**
     * @param array<string, string> $expectedData
     *
     * @dataProvider handleKernelTerminateEventCollectsRequestDataDataProvider
     */
    public function testHandleKernelTerminateEventCollectsRequestData(Options $options, Request $request, array $expectedData, bool $sampled = true): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('getOptions')->willReturn($options);

        $transactionContext = new TransactionContext();
        $transactionContext->setSampled($sampled);
        $transaction = new Transaction($transactionContext);

        $this->hub->method('getClient')->willReturn($client);
        $this->hub->method('getTransaction')->willReturn($transaction);

        $requestFetcher = new RequestFetcher($this->createMock(RequestStack::class));
        $requestFetcher->setRequest($request);

        (new TracingRequestListener($this->hub, $requestFetcher))->handleKernelTerminateEvent(new TerminateEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            new Response()
        ));

        $this->assertEquals($expectedData, array_filter($transaction->getData(), static function (string $key): bool {
            return str_starts_with($key, 'http.request.header.') || 'http.request.body.data' === $key;
        }, \ARRAY_FILTER_USE_KEY));
    }

    public function handleKernelTerminateEventCollectsRequestDataDataProvider(): \Generator
    {
        $server = ['HTTP_HOST' => 'www.example.com', 'CONTENT_TYPE' => 'application/json'];

        yield 'Headers, cookies and the body are collected and filtered' => [
            new Options(['data_collection' => []]),
            new Request([], [], [], ['theme' => 'dark', 'PHPSESSID' => 'secret'], [], $server + [
                'HTTP_AUTHORIZATION' => 'Bearer secret',
                'HTTP_COOKIE' => 'theme=dark; PHPSESSID=secret',
            ], '{"username":"jane","password":"secret"}'),
            [
                'http.request.header.host' => 'www.example.com',
                'http.request.header.content-type' => 'application/json',
                'http.request.header.authorization' => '[Filtered]',
                'http.request.header.cookie' => ['theme=dark', 'PHPSESSID=[Filtered]'],
                'http.request.body.data' => '{"username":"jane","password":"[Filtered]"}',
            ],
        ];

        yield 'A form body is collected and filtered' => [
            new Options(['data_collection' => []]),
            new Request([], ['username' => 'jane', 'password' => 'secret'], [], [], [], ['HTTP_HOST' => 'www.example.com', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded']),
            [
                'http.request.header.host' => 'www.example.com',
                'http.request.header.content-type' => 'application/x-www-form-urlencoded',
                'http.request.body.data' => '{"username":"jane","password":"[Filtered]"}',
            ],
        ];

        yield 'A body that cannot be parsed is filtered' => [
            new Options(['data_collection' => []]),
            new Request([], [], [], [], [], ['HTTP_HOST' => 'www.example.com', 'CONTENT_TYPE' => 'text/plain'], 'Hello World'),
            [
                'http.request.header.host' => 'www.example.com',
                'http.request.header.content-type' => 'text/plain',
                'http.request.body.data' => '[Filtered]',
            ],
        ];

        yield 'A body over the size limit is not collected' => [
            new Options(['data_collection' => [], 'max_request_body_size' => 'small']),
            new Request([], [], [], [], [], $server + ['CONTENT_LENGTH' => '2000'], '{}'),
            [
                'http.request.header.host' => 'www.example.com',
                'http.request.header.content-type' => 'application/json',
                'http.request.header.content-length' => '2000',
            ],
        ];

        yield 'Nothing is collected if headers, cookies and request bodies are disabled' => [
            new Options(['data_collection' => [
                'cookies' => ['mode' => 'off'],
                'http_headers' => ['request' => ['mode' => 'off']],
                'http_bodies' => ['outgoingRequest', 'incomingResponse', 'outgoingResponse'],
            ]]),
            new Request([], [], [], ['theme' => 'dark'], [], $server, '{"username":"jane"}'),
            [],
        ];

        yield 'The legacy options only collect the request data on the event' => [
            new Options(['send_default_pii' => true]),
            new Request([], [], [], ['theme' => 'dark'], [], $server, '{"username":"jane"}'),
            [],
        ];

        yield 'Nothing is collected for transactions that are not sampled' => [
            new Options(['data_collection' => []]),
            new Request([], [], [], ['theme' => 'dark'], [], $server, '{"username":"jane"}'),
            [],
            false,
        ];
    }
}
