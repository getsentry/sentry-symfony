<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Tracing\HttpClient;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\NullLogger;
use Sentry\Client;
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
use Sentry\Transport\NullTransport;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
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
        if (!self::isHttpClientPackageInstalled()) {
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
        $this->assertSame(['sentry-trace: ' . $spans[1]->toTraceparent()], $mockResponse->getRequestOptions()['normalized_headers']['sentry-trace']);
        $this->assertSame(['baggage: ' . $transaction->toBaggage()], $mockResponse->getRequestOptions()['normalized_headers']['baggage']);
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
        $this->assertSame(['sentry-trace: 566e3688a61d4bc888951642d6f14a19-566e3688a61d4bc8'], $mockResponse->getRequestOptions()['normalized_headers']['sentry-trace']);
        $this->assertSame(['baggage: sentry-trace_id=566e3688a61d4bc888951642d6f14a19,sentry-public_key=public,sentry-release=1.0.0,sentry-environment=test'], $mockResponse->getRequestOptions()['normalized_headers']['baggage']);
    }

    public function testRequestSetsUnknownErrorAsSpanStatusIfResponseStatusCodeIsUnavailable(): void
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

    private static function isHttpClientPackageInstalled(): bool
    {
        return interface_exists(HttpClientInterface::class);
    }
}

interface TestableHttpClientInterface extends HttpClientInterface, LoggerAwareInterface, ResetInterface
{
}
