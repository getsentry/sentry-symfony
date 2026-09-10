<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Tracing\HttpClient;

use PHPUnit\Framework\TestCase;
use Sentry\ClientInterface;
use Sentry\DataCollection\DataCollectionPolicy;
use Sentry\Options;
use Sentry\SentryBundle\Tracing\HttpClient\TraceableHttpClient;
use Sentry\SentryBundle\Tracing\HttpClient\TraceableResponse;
use Sentry\State\Hub;
use Sentry\Tracing\Span;
use Sentry\Tracing\TransactionContext;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class HttpBodyCollectionTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(MockHttpClient::class)) {
            $this->markTestSkipped('This test requires symfony/http-client.');
        }
    }

    public function testRepeatedReadsKeepFirstBodyAndTiming(): void
    {
        $span = (new Span())->setSampled(true);
        $underlying = $this->createMock(ResponseInterface::class);
        $underlying->expects($this->once())->method('getHeaders')->with(false)->willReturn(['content-type' => ['application/json']]);
        $underlying->expects($this->exactly(2))->method('getContent')->willReturn('{"token":"secret"}');
        $underlying->method('getInfo')->willReturn(200);
        $response = $this->wrap($underlying, $span);

        $this->assertSame('{"token":"secret"}', $response->getContent());
        $finishedAt = $span->getEndTimestamp();
        $this->assertNotNull($finishedAt);
        $this->assertSame(['token' => '[Filtered]'], $span->getData()['http.response.body.data']);

        $span->setData(['http.response.body.data' => []]);
        $this->assertSame('{"token":"secret"}', $response->getContent());
        $this->assertSame([], $span->getData()['http.response.body.data']);
        $this->assertSame($finishedAt, $span->getEndTimestamp());
    }

    public function testSuccessfulReadAfterDecodingFailure(): void
    {
        $raw = (new MockHttpClient(new MockResponse('{invalid', ['response_headers' => ['Content-Type: application/json']])))->request('GET', 'https://example.com');
        $span = (new Span())->setSampled(true);
        $response = $this->wrap($raw, $span);

        try {
            $response->toArray();
            $this->fail('Expected a decoding exception');
        } catch (DecodingExceptionInterface $exception) {
            $finishedAt = $span->getEndTimestamp();
            $this->assertNotNull($finishedAt);
            $this->assertArrayNotHasKey('http.response.body.data', $span->getData());
            $this->assertSame('{invalid', $response->getContent());
            $this->assertSame('[Filtered]', $span->getData()['http.response.body.data']);
            $this->assertSame($finishedAt, $span->getEndTimestamp());
        }
    }

    public function testDestructionDoesNotReadBody(): void
    {
        $underlying = $this->responseWhoseBodyMustNotBeRead();
        $span = (new Span())->setSampled(true);
        $response = $this->wrap($underlying, $span);

        unset($response);

        $this->assertNotNull($span->getEndTimestamp());
        $this->assertArrayNotHasKey('http.response.body.data', $span->getData());
    }

    public function testCancellationDoesNotReadBody(): void
    {
        $underlying = $this->responseWhoseBodyMustNotBeRead();
        $span = (new Span())->setSampled(true);
        $response = $this->wrap($underlying, $span);

        $response->cancel();
        unset($response);

        $this->assertNotNull($span->getEndTimestamp());
        $this->assertArrayNotHasKey('http.response.body.data', $span->getData());
    }

    /**
     * @dataProvider explicitResponseBodyProvider
     *
     * @param mixed $explicit
     */
    public function testExplicitResponseBodyIsPreserved($explicit): void
    {
        $span = (new Span())->setSampled(true)->setData(['http.response.body.data' => $explicit]);
        $underlying = $this->createMock(ResponseInterface::class);
        $underlying->expects($this->once())->method('toArray')->willReturn(['password' => 'secret']);
        $underlying->method('getHeaders')->willReturn([]);
        $underlying->method('getInfo')->willReturn(200);

        $this->assertSame(['password' => 'secret'], $this->wrap($underlying, $span)->toArray());
        $this->assertSame($explicit, $span->getData()['http.response.body.data']);
    }

    public function explicitResponseBodyProvider(): \Generator
    {
        yield 'null' => [null];
        yield 'empty' => [[]];
        yield 'populated' => [['password' => 'explicit']];
    }

    /**
     * @dataProvider declaredBodyProvider
     *
     * @param array<string, mixed> $requestOptions
     * @param array<string, mixed> $expectedBodyData
     */
    public function testDeclaredRequestBodies(array $requestOptions, array $expectedBodyData): void
    {
        $data = $this->collectDeclaredRequestBody($requestOptions);

        $this->assertSame(
            $expectedBodyData,
            array_intersect_key($data, ['http.request.body.data' => true])
        );
    }

    public function declaredBodyProvider(): \Generator
    {
        yield 'json without header' => [['json' => ['password' => 'secret']], ['http.request.body.data' => ['password' => '[Filtered]']]];
        yield 'empty json' => [['json' => []], ['http.request.body.data' => []]];
        yield 'scalar json' => [['json' => '{"password":"secret"}'], ['http.request.body.data' => '[Filtered]']];
        yield 'empty scalar json' => [['json' => ''], []];
        yield 'empty raw body' => [['body' => ''], []];
        yield 'boolean json' => [['json' => false], []];
        yield 'numeric json' => [['json' => 123], []];
        yield 'json takes precedence' => [['json' => ['name' => 'json'], 'body' => 'ignored'], ['http.request.body.data' => ['name' => 'json']]];
        yield 'empty json does not fall back to body' => [['json' => '', 'body' => 'ignored'], []];
        yield 'null json falls back to body' => [['json' => null, 'body' => ['name' => 'form']], ['http.request.body.data' => ['name' => 'form']]];
        yield 'raw JSON document' => [['body' => '{"password":"secret"}', 'headers' => ['Content-Type' => 'application/json']], ['http.request.body.data' => ['password' => '[Filtered]']]];
        yield 'declared JSON string ignores content type' => [['json' => '{"name":"Alice"}', 'headers' => ['Content-Type' => 'application/json']], ['http.request.body.data' => '[Filtered]']];
        yield 'json object' => [['json' => new \stdClass()], []];
        yield 'form' => [['body' => ['name' => 'Alice', 'password' => 'secret']], ['http.request.body.data' => ['name' => 'Alice', 'password' => '[Filtered]']]];
        yield 'unsupported raw' => [['body' => 'secret'], ['http.request.body.data' => '[Filtered]']];
        yield 'callback' => [['body' => static function (): string {
            throw new \LogicException('Do not call');
        }], []];
    }

    public function testUnbufferedContentIsCollectedFromTheReturnedValue(): void
    {
        $raw = (new MockHttpClient(new MockResponse('{"token":"secret"}', ['response_headers' => ['Content-Type: application/json']])))->request('GET', 'https://example.com', ['buffer' => false]);
        $span = (new Span())->setSampled(true);

        $this->assertSame('{"token":"secret"}', $this->wrap($raw, $span)->getContent());
        $this->assertSame(['token' => '[Filtered]'], $span->getData()['http.response.body.data']);
    }

    public function testToStreamDoesNotCollectBody(): void
    {
        $raw = (new MockHttpClient(new MockResponse('{"token":"secret"}')))->request('GET', 'https://example.com');
        $span = (new Span())->setSampled(true);
        $response = $this->wrap($raw, $span);
        if (!method_exists($response, 'toStream')) {
            $this->markTestSkipped('toStream is not available.');
        }

        $this->assertSame('{"token":"secret"}', stream_get_contents($response->toStream()));
        $this->assertArrayNotHasKey('http.response.body.data', $span->getData());
    }

    public function testRuntimeOptionUpdatesAffectLaterCollection(): void
    {
        $options = new Options(['data_collection' => ['http_bodies' => []]]);
        $span = (new Span())->setSampled(true);
        $underlying = $this->createMock(ResponseInterface::class);
        $underlying->method('getContent')->willReturn('{"name":"Alice"}');
        $underlying->method('getHeaders')->willReturn(['content-type' => ['application/json']]);
        $underlying->method('getInfo')->willReturn(200);
        $response = new TraceableResponse($this->createMock(HttpClientInterface::class), $underlying, $span, DataCollectionPolicy::fromOptions($options));

        $dataCollection = $options->getDataCollection();
        $this->assertNotNull($dataCollection);
        $dataCollection->setHttpBodies(['incomingResponse']);
        $response->getContent();

        $this->assertSame(['name' => 'Alice'], $span->getData()['http.response.body.data']);
    }

    public function testReplacingRuntimeOptionsAffectsLaterCollection(): void
    {
        $options = new Options(['data_collection' => ['http_bodies' => []]]);
        $previous = $options->getDataCollection();
        $span = (new Span())->setSampled(true);
        $underlying = $this->createMock(ResponseInterface::class);
        $underlying->method('getContent')->willReturn('{"name":"Alice"}');
        $underlying->method('getHeaders')->willReturn(['content-type' => ['application/json']]);
        $underlying->method('getInfo')->willReturn(200);
        $response = new TraceableResponse($this->createMock(HttpClientInterface::class), $underlying, $span, DataCollectionPolicy::fromOptions($options));

        $options->updateOptions(['data_collection' => ['http_bodies' => ['incomingResponse']]]);
        $this->assertNotSame($previous, $options->getDataCollection());
        $response->getContent();

        $this->assertSame(['name' => 'Alice'], $span->getData()['http.response.body.data']);
    }

    private function responseWhoseBodyMustNotBeRead(): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->never())->method('getContent');
        $response->expects($this->never())->method('toArray');
        $response->method('getHeaders')->willReturn([]);
        $response->method('getInfo')->willReturn(200);

        return $response;
    }

    /**
     * @param array<string, mixed> $requestOptions
     *
     * @return array<string, mixed>
     */
    private function collectDeclaredRequestBody(array $requestOptions): array
    {
        $sdk = $this->createMock(ClientInterface::class);
        $sdk->method('getOptions')->willReturn(new Options(['data_collection' => [], 'max_request_body_size' => 'always', 'traces_sample_rate' => 1.0]));
        $hub = new Hub($sdk);
        $transaction = $hub->startTransaction(TransactionContext::make());
        $hub->setSpan($transaction);
        $underlying = $this->createMock(HttpClientInterface::class);
        $rawResponse = $this->createMock(ResponseInterface::class);
        $rawResponse->method('getHeaders')->willReturn([]);
        $rawResponse->method('getInfo')->willReturn(200);
        $underlying->expects($this->once())->method('request')->willReturn($rawResponse);

        $client = new TraceableHttpClient($underlying, $hub);
        $client->request('POST', 'https://example.com', $requestOptions)->getContent();
        $recorder = $transaction->getSpanRecorder();
        $this->assertNotNull($recorder);

        return $recorder->getSpans()[1]->getData();
    }

    private function wrap(ResponseInterface $response, Span $span): TraceableResponse
    {
        return new TraceableResponse(
            $this->createMock(HttpClientInterface::class),
            $response,
            $span,
            DataCollectionPolicy::fromOptions(new Options(['data_collection' => []]))
        );
    }
}
