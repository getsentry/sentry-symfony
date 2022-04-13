<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Tracing\HttpClient;

use PHPUnit\Framework\Constraint\Callback;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\Tracing\HttpClient\AbstractTraceableResponse;
use Sentry\SentryBundle\Tracing\HttpClient\TraceableHttpClient;
use Sentry\State\HubInterface;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class TraceableHttpClientTest extends TestCase
{
    /**
     * @var MockObject&HubInterface
     */
    private $hub;

    /**
     * @var MockObject&HttpClientInterface
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
        $this->client = $this->createMock(HttpClientInterface::class);
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
        $this->assertSame('http.request', $spans[1]->getOp());
        $this->assertSame([
            'method' => 'POST',
            'url' => 'http://www.example.org/test-page',
        ], $spans[1]->getTags());

        $response->getContent(false);

        $spans = $transaction->getSpanRecorder()->getSpans();
        $this->assertCount(2, $spans);
        $this->assertNotNull($spans[1]->getEndTimestamp());
        $this->assertSame('http.request', $spans[1]->getOp());
        $this->assertSame([
            'method' => 'POST',
            'url' => 'http://www.example.org/test-page',
        ], $spans[1]->getTags());
    }

    private static function isHttpClientPackageInstalled(): bool
    {
        return interface_exists(HttpClientInterface::class);
    }
}
