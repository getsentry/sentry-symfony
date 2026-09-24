<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Tracing\HttpClient;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\NullLogger;
use Sentry\ClientInterface;
use Sentry\Options;
use Sentry\SentryBundle\Tracing\HttpClient\AbstractTraceableResponse;
use Sentry\SentryBundle\Tracing\HttpClient\TraceableHttpClient;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Sentry\Tracing\PropagationContext;
use Sentry\Tracing\SpanId;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\TraceId;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\Service\ResetInterface;

final class TraceableHttpClientTest extends TestCase
{
    /**
     * @var MockObject&HubInterface
     */
    private $hub;

    /**
     * @var MockObject&TestableHttpClientInterface
     */
    private $decoratedHttpClient;

    /**
     * @var TraceableHttpClient
     */
    private $httpClient;

    public static function setUpBeforeClass(): void
    {
        if (!class_exists(HttpClient::class)) {
            self::markTestSkipped('This test requires the "symfony/http-client" Composer package to be installed.');
        }
    }

    protected function setUp(): void
    {
        $this->hub = $this->createMock(HubInterface::class);
        $this->decoratedHttpClient = $this->createMock(TestableHttpClientInterface::class);
        $this->httpClient = new TraceableHttpClient($this->decoratedHttpClient, $this->hub);
    }

    public function testRequest(): void
    {
        $options = new Options([
            'dsn' => 'http://public:secret@example.com/sentry/1',
        ]);
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())
            ->method('getOptions')
            ->willReturn($options);

        $transaction = new Transaction(new TransactionContext());
        $transaction->initSpanRecorder();

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($transaction);
        $this->hub->expects($this->once())
            ->method('getClient')
            ->willReturn($client);

        $mockResponse = new MockResponse();
        $decoratedHttpClient = new MockHttpClient($mockResponse);
        $httpClient = new TraceableHttpClient($decoratedHttpClient, $this->hub);
        $response = $httpClient->request('GET', 'https://username:password@www.example.com/test-page?foo=bar#baz');

        $this->assertNotNull($transaction->getSpanRecorder());

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertInstanceOf(AbstractTraceableResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('GET', $response->getInfo('http_method'));
        $this->assertSame('https://username:password@www.example.com/test-page?foo=bar#baz', $response->getInfo('url'));
        $this->assertSame([\sprintf('sentry-trace: %s', $spans[1]->toTraceparent())], $mockResponse->getRequestOptions()['normalized_headers']['sentry-trace']);
        $this->assertSame([\sprintf('baggage: %s', $transaction->toBaggage())], $mockResponse->getRequestOptions()['normalized_headers']['baggage']);
        $this->assertArrayNotHasKey('traceparent', $mockResponse->getRequestOptions()['normalized_headers']);
        $this->assertNotNull($transaction->getSpanRecorder());

        $spans = $transaction->getSpanRecorder()->getSpans();
        $expectedData = [
            'http.url' => 'https://www.example.com/test-page',
            'http.request.method' => 'GET',
            'http.query' => 'foo=bar',
            'http.fragment' => 'baz',
        ];

        // Call gc to invoke destructors at the right time.
        unset($response);

        gc_mem_caches();
        gc_collect_cycles();

        $this->assertCount(2, $spans);
        $this->assertNotNull($spans[1]->getEndTimestamp());
        $this->assertSame('http.client', $spans[1]->getOp());
        $this->assertSame('GET https://www.example.com/test-page', $spans[1]->getDescription());
        $this->assertSame(SpanStatus::ok(), $spans[1]->getStatus());
        $this->assertSame($expectedData, $spans[1]->getData());
    }

    /**
     * @param array<string, mixed> $expectedData
     *
     * @dataProvider requestCollectsDataDataProvider
     */
    public function testRequestCollectsData(Options $options, array $expectedData): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())
            ->method('getOptions')
            ->willReturn($options);

        $transactionContext = new TransactionContext();
        $transactionContext->setSampled(true);

        $transaction = new Transaction($transactionContext);
        $transaction->initSpanRecorder();

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($transaction);
        $this->hub->expects($this->once())
            ->method('getClient')
            ->willReturn($client);

        $mockResponse = new MockResponse('', [
            'response_headers' => [
                'Content-Type' => 'application/json',
                'Set-Cookie' => 'session_id=foo; Path=/',
            ],
        ]);
        $httpClient = new TraceableHttpClient(new MockHttpClient($mockResponse), $this->hub);
        $response = $httpClient->request('GET', 'https://username:password@www.example.com/test-page?token=secret&page=1#baz', [
            'headers' => [
                'Authorization' => 'Bearer foo',
                'Accept' => 'application/json',
                'Cookie' => 'theme=dark',
            ],
        ]);

        $response->getContent();

        $this->assertNotNull($transaction->getSpanRecorder());

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertSame($expectedData, $spans[1]->getData());
    }

    /**
     * @return \Generator<mixed>
     */
    public function requestCollectsDataDataProvider(): \Generator
    {
        yield 'The legacy options only collect the query string' => [
            new Options(['send_default_pii' => true]),
            [
                'http.url' => 'https://www.example.com/test-page',
                'http.request.method' => 'GET',
                'http.query' => 'token=secret&page=1',
                'http.fragment' => 'baz',
            ],
        ];

        yield 'The data collection options collect and filter the URL, headers and cookies' => [
            new Options(['data_collection' => []]),
            [
                'http.url' => 'https://www.example.com/test-page',
                'http.request.method' => 'GET',
                'http.query' => 'token=[Filtered]&page=1',
                'http.fragment' => 'baz',
                'url.full' => 'https://[Filtered]:[Filtered]@www.example.com/test-page?token=[Filtered]&page=1#baz',
                'http.request.header.authorization' => '[Filtered]',
                'http.request.header.accept' => 'application/json',
                'http.request.header.cookie.theme' => 'dark',
                'http.response.header.content-type' => 'application/json',
                'http.response.header.set_cookie.session_id' => '[Filtered]',
            ],
        ];

        yield 'The data collection options omit the query string if query parameters are not collected' => [
            new Options(['data_collection' => ['url_query_params' => ['mode' => 'off'], 'http_headers' => ['mode' => 'off'], 'cookies' => ['mode' => 'off']]]),
            [
                'http.url' => 'https://www.example.com/test-page',
                'http.request.method' => 'GET',
                'http.fragment' => 'baz',
                'url.full' => 'https://[Filtered]:[Filtered]@www.example.com/test-page#baz',
            ],
        ];
    }

    /**
     * @param array<array-key, mixed> $headers
     * @param array<string, mixed>    $expectedHeaderData
     *
     * @dataProvider requestCollectsHeadersDataProvider
     */
    public function testRequestCollectsHeaders(array $headers, array $expectedHeaderData): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())
            ->method('getOptions')
            ->willReturn(new Options(['data_collection' => []]));

        $transactionContext = new TransactionContext();
        $transactionContext->setSampled(true);

        $transaction = new Transaction($transactionContext);
        $transaction->initSpanRecorder();

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($transaction);
        $this->hub->expects($this->once())
            ->method('getClient')
            ->willReturn($client);

        $httpClient = new TraceableHttpClient(new MockHttpClient(new MockResponse()), $this->hub);
        $httpClient->request('GET', 'https://www.example.com/', ['headers' => $headers])->getContent();

        $this->assertNotNull($transaction->getSpanRecorder());

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);

        $headerData = array_filter($spans[1]->getData(), static function (string $key): bool {
            return 1 === preg_match('/^http\.request\.header\./', $key);
        }, \ARRAY_FILTER_USE_KEY);

        $this->assertSame($expectedHeaderData, $headerData);
    }

    /**
     * @return \Generator<mixed>
     */
    public function requestCollectsHeadersDataProvider(): \Generator
    {
        yield 'Headers given as a map' => [
            [
                'Authorization' => 'Bearer foo',
                'Accept' => ['application/json', 'text/html'],
                'X-Request-Id' => 1234,
            ],
            [
                'http.request.header.authorization' => '[Filtered]',
                'http.request.header.accept' => 'application/json, text/html',
                'http.request.header.x-request-id' => '1234',
            ],
        ];

        yield 'Headers given as a list' => [
            ['Authorization: Bearer foo', 'Accept:application/json'],
            [
                'http.request.header.authorization' => '[Filtered]',
                'http.request.header.accept' => 'application/json',
            ],
        ];

        yield 'Headers given as objects that can be converted to strings' => [
            [
                new class {
                    public function __toString(): string
                    {
                        return 'X-Request-Id: abc';
                    }
                },
                'X-Trace-Id' => new class {
                    public function __toString(): string
                    {
                        return 'def';
                    }
                },
            ],
            [
                'http.request.header.x-request-id' => 'abc',
                'http.request.header.x-trace-id' => 'def',
            ],
        ];

        yield 'Header names are case-insensitive' => [
            ['accept' => 'text/html', 'Accept' => 'application/json'],
            [
                'http.request.header.accept' => 'application/json',
            ],
        ];

        yield 'Cookies are collected separately from the headers' => [
            ['Cookie' => 'session_id=foo; theme=dark'],
            [
                'http.request.header.cookie.session_id' => '[Filtered]',
                'http.request.header.cookie.theme' => 'dark',
            ],
        ];
    }

    /**
     * @param array<string, mixed>             $requestOptions
     * @param array<string, mixed>|string|null $expectedBody
     *
     * @dataProvider requestCollectsBodyDataProvider
     */
    public function testRequestCollectsBody(Options $options, array $requestOptions, $expectedBody): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())
            ->method('getOptions')
            ->willReturn($options);

        $transactionContext = new TransactionContext();
        $transactionContext->setSampled(true);

        $transaction = new Transaction($transactionContext);
        $transaction->initSpanRecorder();

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($transaction);
        $this->hub->expects($this->once())
            ->method('getClient')
            ->willReturn($client);

        $httpClient = new TraceableHttpClient(new MockHttpClient(new MockResponse()), $this->hub);
        $httpClient->request('POST', 'https://www.example.com/', $requestOptions)->getContent();

        $this->assertNotNull($transaction->getSpanRecorder());

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertSame($expectedBody, $spans[1]->getData()['http.request.body.data'] ?? null);
    }

    /**
     * @return \Generator<mixed>
     */
    public function requestCollectsBodyDataProvider(): \Generator
    {
        yield 'The legacy options do not collect the body' => [
            new Options(['send_default_pii' => true]),
            ['json' => ['username' => 'jane', 'password' => 'secret']],
            null,
        ];

        yield 'The body of the json option is collected and filtered' => [
            new Options(['data_collection' => []]),
            ['json' => ['username' => 'jane', 'password' => 'secret']],
            ['username' => 'jane', 'password' => '[Filtered]'],
        ];

        yield 'The body of the json option is encoded like the HTTP client does' => [
            new Options(['data_collection' => []]),
            [
                'json' => new class implements \JsonSerializable {
                    /**
                     * @return array<string, mixed>
                     */
                    public function jsonSerialize(): array
                    {
                        return ['token' => 'secret', 'page' => 1];
                    }
                },
            ],
            ['token' => '[Filtered]', 'page' => 1],
        ];

        yield 'A body given as a string is decoded based on its content type' => [
            new Options(['data_collection' => []]),
            [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => '{"username":"jane","password":"secret"}',
            ],
            ['username' => 'jane', 'password' => '[Filtered]'],
        ];

        yield 'A body given as a string that cannot be parsed is filtered' => [
            new Options(['data_collection' => []]),
            [
                'headers' => ['Content-Type' => 'text/plain'],
                'body' => 'Hello World',
            ],
            '[Filtered]',
        ];

        yield 'A body given as form fields is collected and filtered' => [
            new Options(['data_collection' => []]),
            ['body' => ['username' => 'jane', 'password' => 'secret']],
            ['username' => 'jane', 'password' => '[Filtered]'],
        ];

        yield 'A body given as a closure is not collected to not consume it' => [
            new Options(['data_collection' => []]),
            [
                'body' => static function (): string {
                    return '';
                },
            ],
            null,
        ];

        yield 'The body is not collected if outgoing request bodies are disabled' => [
            new Options(['data_collection' => ['http_bodies' => ['incomingResponse']]]),
            ['json' => ['username' => 'jane']],
            null,
        ];
    }

    public function testRequestDoesNotContainTracingHeaders(): void
    {
        $options = new Options([
            'dsn' => 'http://public:secret@example.com/sentry/1',
            'trace_propagation_targets' => [],
        ]);
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())
            ->method('getOptions')
            ->willReturn($options);

        $transaction = new Transaction(new TransactionContext());
        $transaction->initSpanRecorder();

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($transaction);
        $this->hub->expects($this->once())
            ->method('getClient')
            ->willReturn($client);

        $mockResponse = new MockResponse();
        $decoratedHttpClient = new MockHttpClient($mockResponse);
        $httpClient = new TraceableHttpClient($decoratedHttpClient, $this->hub);
        $response = $httpClient->request('PUT', 'https://www.example.com/test-page');

        $this->assertInstanceOf(AbstractTraceableResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('PUT', $response->getInfo('http_method'));
        $this->assertSame('https://www.example.com/test-page', $response->getInfo('url'));
        $this->assertArrayNotHasKey('sentry-trace', $mockResponse->getRequestOptions()['normalized_headers']);
        $this->assertArrayNotHasKey('traceparent', $mockResponse->getRequestOptions()['normalized_headers']);
        $this->assertArrayNotHasKey('baggage', $mockResponse->getRequestOptions()['normalized_headers']);
        $this->assertNotNull($transaction->getSpanRecorder());

        $spans = $transaction->getSpanRecorder()->getSpans();
        $expectedData = [
            'http.url' => 'https://www.example.com/test-page',
            'http.request.method' => 'PUT',
        ];

        $this->assertCount(2, $spans);
        $this->assertNull($spans[1]->getEndTimestamp());
        $this->assertSame('http.client', $spans[1]->getOp());
        $this->assertSame('PUT https://www.example.com/test-page', $spans[1]->getDescription());
        $this->assertSame($expectedData, $spans[1]->getData());
    }

    public function testRequestDoesContainsTracingHeadersWithoutTransaction(): void
    {
        $options = new Options([
            'dsn' => 'http://public:secret@example.com/sentry/1',
            'release' => '1.0.0',
            'environment' => 'test',
            'trace_propagation_targets' => ['www.example.com'],
        ]);
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->exactly(4))
            ->method('getOptions')
            ->willReturn($options);

        $propagationContext = PropagationContext::fromDefaults();
        $propagationContext->setTraceId(new TraceId('566e3688a61d4bc888951642d6f14a19'));
        $propagationContext->setSpanId(new SpanId('566e3688a61d4bc8'));

        $scope = new Scope($propagationContext);

        $hub = new Hub($client, $scope);

        SentrySdk::setCurrentHub($hub);

        $mockResponse = new MockResponse();
        $decoratedHttpClient = new MockHttpClient($mockResponse);
        $httpClient = new TraceableHttpClient($decoratedHttpClient, $hub);
        $response = $httpClient->request('POST', 'https://www.example.com/test-page');

        $this->assertInstanceOf(AbstractTraceableResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('POST', $response->getInfo('http_method'));
        $this->assertSame('https://www.example.com/test-page', $response->getInfo('url'));
        $this->assertSame([\sprintf('sentry-trace: %s', $propagationContext->toTraceparent())], $mockResponse->getRequestOptions()['normalized_headers']['sentry-trace']);
        $this->assertSame([\sprintf('baggage: %s', $propagationContext->toBaggage())], $mockResponse->getRequestOptions()['normalized_headers']['baggage']);
    }

    public function testRequestSetsUnknownErrorAsSpanStatusIfResponseStatusCodeIsUnavailable(): void
    {
        $options = new Options([
            'dsn' => 'http://public:secret@example.com/sentry/1',
        ]);
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->exactly(2))
            ->method('getOptions')
            ->willReturn($options);

        $transaction = new Transaction(new TransactionContext(), $this->hub);
        $transaction->initSpanRecorder();

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($transaction);

        $this->hub->expects($this->exactly(2))
            ->method('getClient')
            ->willReturn($client);

        $decoratedHttpClient = new MockHttpClient(new MockResponse());
        $httpClient = new TraceableHttpClient($decoratedHttpClient, $this->hub);

        // Cancelling the response is the only way that does not override in any
        // way the status code and leave it set to 0. This is a required precondition
        // for the span status to be set to the expected value.
        $response = $httpClient->request('GET', 'https://www.example.com/test-page');
        $response->cancel();

        $this->assertNotNull($transaction->getSpanRecorder());
        $this->assertInstanceOf(AbstractTraceableResponse::class, $response);

        // Call gc to invoke destructors at the right time.
        unset($response);

        gc_mem_caches();
        gc_collect_cycles();

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertNotNull($spans[1]->getEndTimestamp());
        $this->assertSame('GET https://www.example.com/test-page', $spans[1]->getDescription());
        $this->assertSame(SpanStatus::unknownError(), $spans[1]->getStatus());
    }

    public function testRequestDoesNotReadResponseDataWithLegacyOptions(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('getOptions')
            ->willReturn(new Options(['send_default_pii' => true, 'trace_propagation_targets' => []]));

        $transactionContext = new TransactionContext();
        $transactionContext->setSampled(true);

        $transaction = new Transaction($transactionContext);
        $transaction->initSpanRecorder();

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($transaction);
        $this->hub->method('getClient')
            ->willReturn($client);

        $decoratedResponse = $this->createMock(ResponseInterface::class);
        // The status code is the only info read, to set the span status
        $decoratedResponse->expects($this->once())
            ->method('getInfo')
            ->with('http_code')
            ->willReturn(200);
        $decoratedResponse->method('getContent')
            ->willReturn('{"foo":"bar"}');

        $this->decoratedHttpClient->expects($this->once())
            ->method('request')
            ->willReturn($decoratedResponse);

        $response = $this->httpClient->request('GET', 'https://www.example.com/test-page');

        $this->assertSame('{"foo":"bar"}', $response->getContent());
        $this->assertSame('{"foo":"bar"}', $response->getContent());

        $this->assertNotNull($transaction->getSpanRecorder());

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertSame(SpanStatus::ok(), $spans[1]->getStatus());
    }

    public function testStream(): void
    {
        $transaction = new Transaction(new TransactionContext());
        $transaction->initSpanRecorder();

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($transaction);

        $decoratedHttpClient = new MockHttpClient(new MockResponse(['foo', 'bar']));
        $httpClient = new TraceableHttpClient($decoratedHttpClient, $this->hub);
        $response = $httpClient->request('GET', 'https://www.example.com/test-page');
        $chunks = [];

        foreach ($httpClient->stream($response) as $chunkResponse => $chunk) {
            $this->assertSame($response, $chunkResponse);

            $chunks[] = $chunk->getContent();
        }

        $this->assertNotNull($transaction->getSpanRecorder());

        $spans = $transaction->getSpanRecorder()->getSpans();
        $expectedData = [
            'http.url' => 'https://www.example.com/test-page',
            'http.request.method' => 'GET',
        ];

        $this->assertSame('foobar', implode('', $chunks));
        $this->assertCount(2, $spans);

        $this->assertNotNull($spans[1]->getEndTimestamp());
        $this->assertSame('http.client', $spans[1]->getOp());
        $this->assertSame('GET https://www.example.com/test-page', $spans[1]->getDescription());
        $this->assertSame($expectedData, $spans[1]->getData());

        $loopIndex = 0;

        foreach ($httpClient->stream($response) as $chunk) {
            ++$loopIndex;
        }

        $this->assertSame(1, $loopIndex);
    }

    public function testStreamThrowsExceptionIfResponsesArgumentIsInvalid(): void
    {
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('"Sentry\\SentryBundle\\Tracing\\HttpClient\\AbstractTraceableHttpClient::stream()" expects parameter 1 to be an iterable of TraceableResponse objects, "stdClass" given.');

        $this->httpClient->stream(new \stdClass());
    }

    public function testSetLogger(): void
    {
        $logger = new NullLogger();

        $this->decoratedHttpClient->expects($this->once())
            ->method('setLogger')
            ->with($logger);

        $this->httpClient->setLogger($logger);
    }

    public function testReset(): void
    {
        $this->decoratedHttpClient->expects($this->once())
            ->method('reset');

        $this->httpClient->reset();
    }

    public function testWithOptions(): void
    {
        if (!method_exists(MockHttpClient::class, 'withOptions')) {
            self::markTestSkipped();
        }

        $transaction = new Transaction(new TransactionContext());
        $transaction->initSpanRecorder();

        $this->hub->expects($this->exactly(2))
            ->method('getSpan')
            ->willReturn($transaction);

        $responses = [
            new MockResponse(),
            new MockResponse(),
        ];

        $decoratedHttpClient = new MockHttpClient($responses, 'https://www.example.com');
        $httpClient1 = new TraceableHttpClient($decoratedHttpClient, $this->hub);
        $httpClient2 = $httpClient1->withOptions(['base_uri' => 'https://www.example.org']);

        $this->assertNotSame($httpClient1, $httpClient2);

        $response = $httpClient1->request('GET', 'test-page');

        $this->assertInstanceOf(AbstractTraceableResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('GET', $response->getInfo('http_method'));
        $this->assertSame('https://www.example.com/test-page', $response->getInfo('url'));

        $response = $httpClient2->request('GET', 'test-page');

        $this->assertInstanceOf(AbstractTraceableResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('GET', $response->getInfo('http_method'));
        $this->assertSame('https://www.example.org/test-page', $response->getInfo('url'));
    }

    public function testRequestCollectsDefaultHeaders(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())
            ->method('getOptions')
            ->willReturn(new Options(['data_collection' => [], 'trace_propagation_targets' => []]));

        $transactionContext = new TransactionContext();
        $transactionContext->setSampled(true);

        $transaction = new Transaction($transactionContext);
        $transaction->initSpanRecorder();

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($transaction);
        $this->hub->expects($this->once())
            ->method('getClient')
            ->willReturn($client);

        $httpClient = new TraceableHttpClient(new MockHttpClient(new MockResponse()), $this->hub, [
            'User-Agent' => 'my-app',
            'Accept' => 'text/html',
        ]);
        $httpClient->request('GET', 'https://www.example.com/', ['headers' => ['Accept' => 'application/json']])->getContent();

        $this->assertNotNull($transaction->getSpanRecorder());

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertSame('application/json', $spans[1]->getData()['http.request.header.accept'] ?? null);
        $this->assertSame('my-app', $spans[1]->getData()['http.request.header.user-agent'] ?? null);
    }

    public function testRequestCollectsHeadersOfWithOptions(): void
    {
        if (!method_exists(MockHttpClient::class, 'withOptions')) {
            self::markTestSkipped('This test requires the withOptions() method of the HTTP client.');
        }

        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())
            ->method('getOptions')
            ->willReturn(new Options(['data_collection' => [], 'trace_propagation_targets' => []]));

        $transactionContext = new TransactionContext();
        $transactionContext->setSampled(true);

        $transaction = new Transaction($transactionContext);
        $transaction->initSpanRecorder();

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($transaction);
        $this->hub->expects($this->once())
            ->method('getClient')
            ->willReturn($client);

        $httpClient = (new TraceableHttpClient(new MockHttpClient(new MockResponse()), $this->hub, ['User-Agent' => 'my-app']))
            ->withOptions(['headers' => ['User-Agent' => 'my-other-app', 'X-Api-Version' => '2']]);
        $httpClient->request('GET', 'https://www.example.com/')->getContent();

        $this->assertNotNull($transaction->getSpanRecorder());

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertSame('my-other-app', $spans[1]->getData()['http.request.header.user-agent'] ?? null);
        $this->assertSame('2', $spans[1]->getData()['http.request.header.x-api-version'] ?? null);
    }
}

if (interface_exists(HttpClientInterface::class)) {
    interface TestableHttpClientInterface extends HttpClientInterface, LoggerAwareInterface, ResetInterface
    {
    }
}
