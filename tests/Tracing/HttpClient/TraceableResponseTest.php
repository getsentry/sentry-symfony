<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Tracing\HttpClient;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sentry\DataCollection\DataCollectionPolicy;
use Sentry\Options;
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
use Symfony\Contracts\HttpClient\ResponseInterface;

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

    /**
     * @param array<string, mixed> $expectedData
     *
     * @dataProvider getContentCollectsResponseDataDataProvider
     */
    public function testGetContentCollectsResponseData(Options $options, array $expectedData): void
    {
        $spanContext = new SpanContext();
        $spanContext->setSampled(true);

        $span = new Span($spanContext);
        $httpClient = new MockHttpClient(new MockResponse('', [
            'response_headers' => [
                'HTTP/1.1 200 OK',
                'Content-Type: application/json',
                'X-Auth-Token: foo',
                'Vary: Accept',
                'vary: Accept-Encoding',
                'Set-Cookie: session_id=foo; Path=/; HttpOnly',
                'Set-Cookie: theme=dark',
            ],
        ]));
        $response = new TraceableResponse($httpClient, $httpClient->request('GET', 'https://www.example.org/'), $span, DataCollectionPolicy::fromOptions($options));

        $response->getContent();

        $this->assertSame($expectedData, $span->getData());
    }

    /**
     * @return \Generator<mixed>
     */
    public function getContentCollectsResponseDataDataProvider(): \Generator
    {
        yield 'The legacy options do not collect response headers' => [
            new Options(['send_default_pii' => true]),
            [],
        ];

        yield 'The data collection options collect and filter response headers and cookies' => [
            new Options(['data_collection' => []]),
            [
                'http.response.header.content-type' => ['application/json'],
                'http.response.header.x-auth-token' => ['[Filtered]'],
                'http.response.header.vary' => ['Accept', 'Accept-Encoding'],
                'http.response.header.set_cookie.session_id' => '[Filtered]',
                'http.response.header.set_cookie.theme' => 'dark',
            ],
        ];

        yield 'The data collection options only collect cookies if response headers are disabled' => [
            new Options(['data_collection' => ['http_headers' => ['response' => ['mode' => 'off']]]]),
            [
                'http.response.header.set_cookie.session_id' => '[Filtered]',
                'http.response.header.set_cookie.theme' => 'dark',
            ],
        ];
    }

    public function testResponseDataIsCollectedOnceTheHeadersAreReceived(): void
    {
        $spanContext = new SpanContext();
        $spanContext->setSampled(true);

        $span = new Span($spanContext);
        $httpCode = 0;
        $decoratedResponse = $this->createMock(ResponseInterface::class);
        $decoratedResponse->method('getInfo')
            ->willReturnCallback(static function (?string $type) use (&$httpCode) {
                return 'http_code' === $type ? $httpCode : ['HTTP/1.1 200 OK', 'Content-Type: application/json'];
            });
        $decoratedResponse->expects($this->never())
            ->method('getHeaders');

        $response = new TraceableResponse($this->client, $decoratedResponse, $span, DataCollectionPolicy::fromOptions(new Options(['data_collection' => []])));

        $response->getContent();
        $endTimestamp = $span->getEndTimestamp();

        $this->assertNotNull($endTimestamp);
        $this->assertSame([], $span->getData());

        $httpCode = 200;
        $response->getContent();

        $this->assertSame($endTimestamp, $span->getEndTimestamp());
        $this->assertSame(['http.response.header.content-type' => ['application/json']], $span->getData());
    }

    public function testResponseDataOnlyContainsTheHeadersOfTheLastResponseOfARedirectChain(): void
    {
        $spanContext = new SpanContext();
        $spanContext->setSampled(true);

        $span = new Span($spanContext);
        $httpClient = new MockHttpClient(new MockResponse('', [
            'response_headers' => [
                'HTTP/1.1 302 Found',
                'Location: /target',
                'Set-Cookie: redirect=foo',
                'HTTP/1.1 200 OK',
                'Content-Type: application/json',
            ],
        ]));
        $response = new TraceableResponse($httpClient, $httpClient->request('GET', 'https://www.example.org/'), $span, DataCollectionPolicy::fromOptions(new Options(['data_collection' => []])));

        $response->getContent();

        $this->assertSame(['http.response.header.content-type' => ['application/json']], $span->getData());
    }

    /**
     * @param array<string, mixed>|string|null $expectedBody
     *
     * @dataProvider getContentCollectsResponseBodyDataProvider
     */
    public function testGetContentCollectsResponseBody(Options $options, string $contentType, string $content, $expectedBody): void
    {
        $spanContext = new SpanContext();
        $spanContext->setSampled(true);

        $span = new Span($spanContext);
        $httpClient = new MockHttpClient(new MockResponse($content, ['response_headers' => ['Content-Type: ' . $contentType]]));
        $response = new TraceableResponse($httpClient, $httpClient->request('GET', 'https://www.example.org/'), $span, DataCollectionPolicy::fromOptions($options));

        $this->assertSame($content, $response->getContent());
        $this->assertSame($expectedBody, $span->getData()['http.response.body.data'] ?? null);
    }

    /**
     * @return \Generator<mixed>
     */
    public function getContentCollectsResponseBodyDataProvider(): \Generator
    {
        yield 'The legacy options do not collect the body' => [
            new Options(['send_default_pii' => true]),
            'application/json',
            '{"username":"jane","password":"secret"}',
            null,
        ];

        yield 'A JSON body is collected and filtered' => [
            new Options(['data_collection' => []]),
            'application/json',
            '{"username":"jane","password":"secret"}',
            ['username' => 'jane', 'password' => '[Filtered]'],
        ];

        yield 'A body that cannot be parsed is filtered' => [
            new Options(['data_collection' => []]),
            'text/html',
            '<p>Hello World</p>',
            '[Filtered]',
        ];

        yield 'The body is not collected if incoming response bodies are disabled' => [
            new Options(['data_collection' => ['http_bodies' => ['outgoingRequest']]]),
            'application/json',
            '{"username":"jane"}',
            null,
        ];
    }

    public function testToArrayCollectsResponseBody(): void
    {
        $spanContext = new SpanContext();
        $spanContext->setSampled(true);

        $span = new Span($spanContext);
        $httpClient = new MockHttpClient(new MockResponse('{"username":"jane","password":"secret"}', ['response_headers' => ['Content-Type: application/json']]));
        $response = new TraceableResponse($httpClient, $httpClient->request('GET', 'https://www.example.org/'), $span, DataCollectionPolicy::fromOptions(new Options(['data_collection' => []])));

        $this->assertSame(['username' => 'jane', 'password' => 'secret'], $response->toArray());
        $this->assertSame(['username' => 'jane', 'password' => '[Filtered]'], $span->getData()['http.response.body.data'] ?? null);
    }

    public function testResponseBodyIsCollectedWhenReadAfterStreaming(): void
    {
        $spanContext = new SpanContext();
        $spanContext->setSampled(true);

        $span = new Span($spanContext);
        $httpClient = new MockHttpClient(new MockResponse('{"password":"secret"}', ['response_headers' => ['Content-Type: application/json']]));
        $response = new TraceableResponse($httpClient, $httpClient->request('GET', 'https://www.example.org/'), $span, DataCollectionPolicy::fromOptions(new Options(['data_collection' => []])));

        foreach (TraceableResponse::stream($httpClient, [$response], null) as $chunk) {
        }

        $this->assertSame(['application/json'], $span->getData()['http.response.header.content-type'] ?? null);
        $this->assertArrayNotHasKey('http.response.body.data', $span->getData());

        $response->getContent();

        $this->assertSame(['password' => '[Filtered]'], $span->getData()['http.response.body.data'] ?? null);
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
