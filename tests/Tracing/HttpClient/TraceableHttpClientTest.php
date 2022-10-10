<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Tracing\HttpClient;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\NullLogger;
use Sentry\SentryBundle\Tracing\HttpClient\AbstractTraceableResponse;
use Sentry\SentryBundle\Tracing\HttpClient\TraceableHttpClient;
use Sentry\State\HubInterface;
use Sentry\Tracing\DynamicSamplingContext;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
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
        $samplingContext = DynamicSamplingContext::fromHeader('sentry-trace_id=566e3688a61d4bc888951642d6f14a19,sentry-public_key=public,sentry-sample_rate=1');

        $transactionContext = new TransactionContext();
        $transactionContext->getMetadata()->setDynamicSamplingContext($samplingContext);

        $transaction = new Transaction($transactionContext);
        $transaction->initSpanRecorder();

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($transaction);

        $mockResponse = new MockResponse();
        $decoratedHttpClient = new MockHttpClient($mockResponse);
        $httpClient = new TraceableHttpClient($decoratedHttpClient, $this->hub);
        $response = $httpClient->request('GET', 'https://www.example.com/test-page');

        $this->assertInstanceOf(AbstractTraceableResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('GET', $response->getInfo('http_method'));
        $this->assertSame('https://www.example.com/test-page', $response->getInfo('url'));
        $this->assertSame(['sentry-trace: ' . $transaction->toTraceparent()], $mockResponse->getRequestOptions()['normalized_headers']['sentry-trace']);
        $this->assertSame(['baggage: ' . $transaction->toBaggage()], $mockResponse->getRequestOptions()['normalized_headers']['baggage']);
        $this->assertNotNull($transaction->getSpanRecorder());

        $spans = $transaction->getSpanRecorder()->getSpans();
        $expectedTags = [
            'http.method' => 'GET',
            'http.url' => 'https://www.example.com/test-page',
        ];

        $this->assertCount(2, $spans);
        $this->assertNull($spans[1]->getEndTimestamp());
        $this->assertSame('http.client', $spans[1]->getOp());
        $this->assertSame('HTTP GET', $spans[1]->getDescription());
        $this->assertSame($expectedTags, $spans[1]->getTags());
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
        $expectedTags = [
            'http.method' => 'GET',
            'http.url' => 'https://www.example.com/test-page',
        ];

        $this->assertSame('foobar', implode('', $chunks));
        $this->assertCount(2, $spans);
        $this->assertNotNull($spans[1]->getEndTimestamp());
        $this->assertSame('http.client', $spans[1]->getOp());
        $this->assertSame('HTTP GET', $spans[1]->getDescription());
        $this->assertSame($expectedTags, $spans[1]->getTags());

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
