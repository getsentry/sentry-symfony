<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Tracing\HttpClient;

use PHPUnit\Framework\Constraint\Callback;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\NullLogger;
use Sentry\SentryBundle\Tracing\HttpClient\AbstractTraceableResponse;
use Sentry\SentryBundle\Tracing\HttpClient\TraceableHttpClient;
use Sentry\State\HubInterface;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
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
     * @var MockObject&HttpClientInterface&LoggerAwareInterface&ResetInterface
     */
    private $client;

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
        $this->client = $this->createMock(TestableHttpClientInterface::class);
        $this->httpClient = new TraceableHttpClient($this->client, $this->hub);
    }

    public function testRequest(): void
    {
        $transaction = new Transaction(new TransactionContext());
        $transaction->initSpanRecorder();

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($transaction);

        $response = $this->createMock(ResponseInterface::class);
        $this->client->expects($this->once())
            ->method('request')
            ->with('POST', 'http://www.example.org/test-page', new Callback(function ($value) use ($transaction) {
                $this->assertArrayHasKey('headers', $value);

                return ['sentry-trace' => $transaction->toTraceparent()] === $value['headers'];
            }))
            ->willReturn($response);

        $response = $this->httpClient->request('POST', 'http://www.example.org/test-page', []);

        $this->assertInstanceOf(AbstractTraceableResponse::class, $response);
        $this->assertNotNull($transaction->getSpanRecorder());
        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertNull($spans[1]->getEndTimestamp());
        $this->assertSame('http.client', $spans[1]->getOp());
        $this->assertSame('HTTP POST', $spans[1]->getDescription());
        $this->assertSame([
            'http.method' => 'POST',
            'http.url' => 'http://www.example.org/test-page',
        ], $spans[1]->getTags());

        $response->getContent(false);

        $spans = $transaction->getSpanRecorder()->getSpans();
        $this->assertCount(2, $spans);
        $this->assertNotNull($spans[1]->getEndTimestamp());
        $this->assertSame('http.client', $spans[1]->getOp());
        $this->assertSame('HTTP POST', $spans[1]->getDescription());
        $this->assertSame([
            'http.method' => 'POST',
            'http.url' => 'http://www.example.org/test-page',
        ], $spans[1]->getTags());
    }

    public function testSetLoggerShouldBeForwardedToDecoratedInstance(): void
    {
        $logger = new NullLogger();
        $this->client->expects($this->once())
            ->method('setLogger')
            ->with($logger);

        $this->httpClient->setLogger($logger);
    }

    public function testResetCallShouldBeForwardedToDecoratedInstance(): void
    {
        $this->client->expects($this->once())
            ->method('reset');

        $this->httpClient->reset();
    }

    public function testWithOptions(): void
    {
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
