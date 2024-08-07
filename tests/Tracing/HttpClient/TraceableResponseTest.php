<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Tracing\HttpClient;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\Tracing\HttpClient\TraceableResponse;
use Sentry\State\HubInterface;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class TraceableResponseTest extends TestCase
{
    /**
     * @var MockObject&HttpClientInterface
     */
    private $client;

    /**
     * @var MockObject&HubInterface
     */
    private $hub;

    public static function setUpBeforeClass(): void
    {
        if (!class_exists(HttpClient::class)) {
            self::markTestSkipped('This test requires the "symfony/http-client" Composer package to be installed.');
        }
    }

    protected function setUp(): void
    {
        $this->client = $this->createMock(HttpClientInterface::class);
        $this->hub = $this->createMock(HubInterface::class);
    }

    public function testInstanceCannotBeSerialized(): void
    {
        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Serializing instances of this class is forbidden.');

        serialize(new TraceableResponse($this->client, new MockResponse(), null));
    }

    public function testInstanceCannotBeUnserialized(): void
    {
        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Unserializing instances of this class is forbidden.');

        unserialize(\sprintf('O:%u:"%s":0:{}', \strlen(TraceableResponse::class), TraceableResponse::class));
    }

    public function testDestructor(): void
    {
        $transaction = new Transaction(new TransactionContext(), $this->hub);
        $context = new SpanContext();
        $span = $transaction->startChild($context);
        $response = new TraceableResponse($this->client, new MockResponse(), $span);

        // Call gc to invoke destructors at the right time.
        unset($response);

        gc_mem_caches();
        gc_collect_cycles();

        $this->assertNotNull($span->getEndTimestamp());
    }

    public function testGetStatusCode(): void
    {
        $response = new TraceableResponse($this->client, new MockResponse(), null);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testGetHeaders(): void
    {
        $expectedHeaders = ['content-length' => ['0']];
        $response = new TraceableResponse($this->client, new MockResponse('', ['response_headers' => $expectedHeaders]), null);

        $this->assertSame($expectedHeaders, $response->getHeaders());
    }

    public function testGetContent(): void
    {
        $span = new Span();
        $httpClient = new MockHttpClient(new MockResponse('foobar'));
        $response = new TraceableResponse($httpClient, $httpClient->request('GET', 'https://www.example.org/'), $span);

        $this->assertSame('foobar', $response->getContent());
        $this->assertNotNull($span->getEndTimestamp());
    }

    public function testToArray(): void
    {
        $span = new Span();
        $httpClient = new MockHttpClient(new MockResponse('{"foo":"bar"}'));
        $response = new TraceableResponse($this->client, $httpClient->request('GET', 'https://www.example.org/'), $span);

        $this->assertSame(['foo' => 'bar'], $response->toArray());
        $this->assertNotNull($span->getEndTimestamp());
    }

    public function testCancel(): void
    {
        $span = new Span();
        $response = new TraceableResponse($this->client, new MockResponse(), $span);

        $response->cancel();

        $this->assertTrue($response->getInfo('canceled'));
        $this->assertNotNull($span->getEndTimestamp());
    }

    public function testGetInfo(): void
    {
        $response = new TraceableResponse($this->client, new MockResponse(), null);

        $this->assertSame(200, $response->getInfo('http_code'));
    }

    public function testToStream(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('foobar'));
        $response = new TraceableResponse($this->client, $httpClient->request('GET', 'https://www.example.org/'), null);

        if (!method_exists($response, 'toStream')) {
            $this->markTestSkipped('The TraceableResponse::toStream() method is not supported');
        }

        $this->assertSame('foobar', stream_get_contents($response->toStream()));
    }

    public function testStreamThrowsExceptionIfResponsesArgumentIsInvalid(): void
    {
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('"Sentry\\SentryBundle\\Tracing\\HttpClient\\TraceableHttpClient::stream()" expects parameter 1 to be an iterable of TraceableResponse objects, "stdClass" given.');

        iterator_to_array(TraceableResponse::stream($this->client, [new \stdClass()], null));
    }
}
