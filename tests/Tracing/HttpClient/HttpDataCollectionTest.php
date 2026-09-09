<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Tracing\HttpClient;

use PHPUnit\Framework\TestCase;
use Sentry\ClientInterface;
use Sentry\DataCollection\DataCollectionOptions;
use Sentry\Options;
use Sentry\SentryBundle\Tracing\HttpClient\TraceableHttpClient;
use Sentry\SentryBundle\Tracing\HttpClient\TraceableResponse;
use Sentry\State\Hub;
use Sentry\Tracing\Span;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class HttpDataCollectionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!class_exists(MockHttpClient::class)) {
            self::markTestSkipped('This test requires symfony/http-client.');
        }
    }

    /**
     * @dataProvider legacyPiiProvider
     */
    public function testConfiguredDefaultsCollectHeadersAndCookies(bool $pii): void
    {
        $data = $this->collect(['data_collection' => [], 'send_default_pii' => $pii]);

        $this->assertCollectedHeaders($data, 'request');
        $this->assertCollectedHeaders($data, 'response');
        $this->assertCollectedCookies($data);
    }

    /**
     * @dataProvider legacyPiiProvider
     */
    public function testLegacyConfigurationDoesNotAddHeadersOrCookies(bool $pii): void
    {
        $data = $this->collect(['data_collection' => null, 'send_default_pii' => $pii]);

        $this->assertArrayNotHasKey('http.request.header.x-request', $data);
        $this->assertArrayNotHasKey('http.response.header.x-response', $data);
        $this->assertArrayNotHasKey('http.request.header.cookie.theme', $data);
        $this->assertArrayNotHasKey('http.response.header.set_cookie.theme', $data);
    }

    /**
     * @dataProvider legacyPiiProvider
     */
    public function testDisablingHeadersStillCollectsCookies(bool $pii): void
    {
        $data = $this->collect(['data_collection' => ['http_headers' => ['mode' => 'off']], 'send_default_pii' => $pii]);

        $this->assertArrayNotHasKey('http.request.header.x-request', $data);
        $this->assertArrayNotHasKey('http.response.header.x-response', $data);
        $this->assertCollectedCookies($data);
    }

    /**
     * @dataProvider legacyPiiProvider
     */
    public function testRequestHeadersCanBeDisabledIndependently(bool $pii): void
    {
        $data = $this->collect(['data_collection' => ['http_headers' => ['request' => ['mode' => 'off']]], 'send_default_pii' => $pii]);

        $this->assertArrayNotHasKey('http.request.header.x-request', $data);
        $this->assertCollectedHeaders($data, 'response');
        $this->assertCollectedCookies($data);
    }

    /**
     * @dataProvider legacyPiiProvider
     */
    public function testResponseHeadersCanBeDisabledIndependently(bool $pii): void
    {
        $data = $this->collect(['data_collection' => ['http_headers' => ['response' => ['mode' => 'off']]], 'send_default_pii' => $pii]);

        $this->assertCollectedHeaders($data, 'request');
        $this->assertArrayNotHasKey('http.response.header.x-response', $data);
        $this->assertCollectedCookies($data);
    }

    /**
     * @dataProvider legacyPiiProvider
     */
    public function testDisablingCookiesStillCollectsHeaders(bool $pii): void
    {
        $data = $this->collect(['data_collection' => ['cookies' => ['mode' => 'off']], 'send_default_pii' => $pii]);

        $this->assertCollectedHeaders($data, 'request');
        $this->assertCollectedHeaders($data, 'response');
        $this->assertArrayNotHasKey('http.request.header.cookie.theme', $data);
        $this->assertArrayNotHasKey('http.response.header.set_cookie.theme', $data);
    }

    /**
     * @dataProvider bodyConfigurationProvider
     *
     * @param string[] $bodyTypes
     */
    public function testBodyConfigurationDoesNotChangeHeaderOrCookieCollection(array $bodyTypes, bool $pii): void
    {
        $data = $this->collect(['data_collection' => ['http_bodies' => $bodyTypes], 'send_default_pii' => $pii]);

        $this->assertCollectedHeaders($data, 'request');
        $this->assertCollectedHeaders($data, 'response');
        $this->assertCollectedCookies($data);
        $this->assertArrayNotHasKey('http.request.body.data', $data);
        $this->assertArrayNotHasKey('http.response.body.data', $data);
    }

    public function legacyPiiProvider(): \Generator
    {
        yield 'legacy PII disabled' => [false];
        yield 'legacy PII enabled' => [true];
    }

    public function bodyConfigurationProvider(): \Generator
    {
        foreach ([false, true] as $pii) {
            yield 'bodies disabled pii=' . (int) $pii => [[], $pii];
            yield 'outgoing body enabled pii=' . (int) $pii => [['outgoingRequest'], $pii];
            yield 'incoming body enabled pii=' . (int) $pii => [['incomingResponse'], $pii];
        }
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function collect(array $options): array
    {
        $transaction = null;
        $responseBody = '{"name":"Bob","token":"response-secret"}';
        $mock = new MockResponse($responseBody, ['response_headers' => [
            'Content-Type: application/json', 'X-Response: visible', 'Authorization: response-secret',
            'Set-Cookie: session_id=response-secret; HttpOnly', 'Set-Cookie: theme=light; Path=/',
        ]]);
        $client = $this->client(new MockHttpClient($mock), $options, $transaction);
        $requestBody = '{"name":"Alice","password":"request-secret"}';
        $response = $client->request('POST', 'https://example.com', ['headers' => [
            'Content-Type' => 'application/json', 'X-Request' => 'visible', 'Authorization' => 'request-secret',
            'Cookie' => 'session_id=request-secret; theme=dark',
        ], 'body' => $requestBody]);
        $this->assertSame($responseBody, $response->getContent());
        $this->assertSame($requestBody, $mock->getRequestOptions()['body']);
        $this->assertSame(['Cookie: session_id=request-secret; theme=dark'], $mock->getRequestOptions()['normalized_headers']['cookie']);
        $data = $this->span($transaction)->getData();
        foreach (['http.request.body.data', 'http.response.body.data', 'http.request.header.cookie', 'http.request.header.set-cookie', 'http.response.header.cookie', 'http.response.header.set-cookie'] as $key) {
            $this->assertArrayNotHasKey($key, $data);
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function assertCollectedHeaders(array $data, string $direction): void
    {
        $this->assertSame(['visible'], $data['http.' . $direction . '.header.x-' . $direction]);
        $this->assertSame(['[Filtered]'], $data['http.' . $direction . '.header.authorization']);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function assertCollectedCookies(array $data): void
    {
        $this->assertSame('dark', $data['http.request.header.cookie.theme']);
        $this->assertSame('light', $data['http.response.header.set_cookie.theme']);
        $this->assertSame('[Filtered]', $data['http.request.header.cookie.session_id']);
        $this->assertSame('[Filtered]', $data['http.response.header.set_cookie.session_id']);
    }

    public function testToArrayCollectsHeadersWithoutRereadingContent(): void
    {
        $body = '{"name":"Bob","token":"secret"}';
        $transaction = null;
        $client = $this->client(new MockHttpClient(new MockResponse($body, ['response_headers' => [
            'Content-Type: application/json', 'Content-Length: ' . \strlen($body),
        ]])), ['data_collection' => []], $transaction);
        $this->assertSame(['name' => 'Bob', 'token' => 'secret'], $client->request('GET', 'https://example.com', ['buffer' => false])->toArray());
        $this->assertArrayNotHasKey('http.response.body.data', $this->span($transaction)->getData());
    }

    public function testResponseHeadersAreCollectedOnce(): void
    {
        $span = (new Span())->setSampled(true);
        $headers = ['content-type' => ['application/json'], 'x-response' => ['visible']];
        $body = '{"name":"Bob","token":"secret"}';
        $underlying = $this->createMock(ResponseInterface::class);
        $underlying->method('getStatusCode')->willReturn(200);
        $underlying->expects($this->exactly(2))->method('getHeaders')->willReturn($headers);
        $underlying->method('getContent')->willReturn($body);
        $underlying->method('toArray')->willReturn(['name' => 'Bob', 'token' => 'secret']);
        $underlying->method('getInfo')->willReturnCallback(function (?string $type = null) {
            $this->assertNotSame('response_headers', $type);

            return 'http_code' === $type ? 200 : null;
        });
        $response = new TraceableResponse($this->createMock(HttpClientInterface::class), $underlying, $span, new DataCollectionOptions());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($headers, $response->getHeaders());
        $this->assertArrayNotHasKey('http.response.header.x-response', $span->getData());
        $this->assertSame($body, $response->getContent());
        $this->assertSame(['name' => 'Bob', 'token' => 'secret'], $response->toArray());
        unset($response);

        $this->assertSame(['visible'], $span->getData()['http.response.header.x-response']);
        $this->assertArrayNotHasKey('http.response.body.data', $span->getData());
    }

    public function testBodyAccessorsFinishTimingBeforeCollectingHeaders(): void
    {
        foreach (['getContent', 'toArray'] as $method) {
            $span = (new Span())->setSampled(true);
            $underlying = $this->createMock(ResponseInterface::class);
            $underlying->method('getContent')->willReturn('{"name":"Bob"}');
            $underlying->method('toArray')->willReturn(['name' => 'Bob']);
            $underlying->expects($this->once())->method('getHeaders')->with(false)->willReturnCallback(function () use ($span): array {
                $this->assertNotNull($span->getEndTimestamp());

                return ['content-type' => ['application/json']];
            });
            $underlying->method('getInfo')->willReturnCallback(function (?string $type = null) use ($span) {
                $this->assertNotNull($span->getEndTimestamp());
                $this->assertNotSame('response_headers', $type);

                return 200;
            });
            $response = new TraceableResponse($this->createMock(HttpClientInterface::class), $underlying, $span, new DataCollectionOptions());
            $response->{$method}();

            $this->assertArrayNotHasKey('http.response.body.data', $span->getData());
        }
    }

    public function testDestructionCollectsHeadersWithoutReadingTheBody(): void
    {
        $span = (new Span())->setSampled(true);
        $underlying = $this->createMock(ResponseInterface::class);
        $underlying->expects($this->never())->method('getContent');
        $underlying->expects($this->never())->method('toArray');
        $underlying->expects($this->once())->method('getHeaders')->with(false)->willReturn(['x-response' => ['visible']]);
        $underlying->expects($this->once())->method('getInfo')->with('http_code')->willReturn(200);
        $response = new TraceableResponse($this->createMock(HttpClientInterface::class), $underlying, $span, new DataCollectionOptions());
        unset($response);

        $this->assertSame(['visible'], $span->getData()['http.response.header.x-response']);
        $this->assertNotNull($span->getEndTimestamp());
    }

    public function testUnavailableHeadersDoNotPreventReturningTheBody(): void
    {
        $span = (new Span())->setSampled(true);
        $underlying = $this->createMock(ResponseInterface::class);
        $underlying->method('toArray')->willReturn(['name' => 'Bob', 'token' => 'secret']);
        $underlying->expects($this->once())->method('getHeaders')->with(false)->willThrowException(new TransportException('Headers unavailable'));
        $underlying->method('getInfo')->willReturnCallback(static function (?string $type = null) {
            return 'http_code' === $type ? 200 : null;
        });
        $response = new TraceableResponse($this->createMock(HttpClientInterface::class), $underlying, $span, new DataCollectionOptions());

        $this->assertSame(['name' => 'Bob', 'token' => 'secret'], $response->toArray());
        $this->assertSame([], $span->getData());
    }

    public function testStreamingDoesNotAcquireBody(): void
    {
        $transaction = null;
        $client = $this->client(new MockHttpClient(new MockResponse('secret', ['response_headers' => ['X-Response: visible']])), ['data_collection' => []], $transaction);
        $response = $client->request('GET', 'https://example.com', ['buffer' => false]);
        $content = '';
        foreach ($client->stream($response) as $chunk) {
            $this->assertFalse($chunk->isTimeout());
            $content .= $chunk->getContent();
        }
        $this->assertSame('secret', $content);
        $this->assertSame(['visible'], $this->span($transaction)->getData()['http.response.header.x-response']);
        $this->assertArrayNotHasKey('http.response.body.data', $this->span($transaction)->getData());
    }

    public function testFinishingWithoutSpanOrCollectionOptionsDoesNotReadHeaders(): void
    {
        foreach ([
            [null, new DataCollectionOptions()],
            [(new Span())->setSampled(true), null],
        ] as [$span, $options]) {
            $underlying = $this->createMock(ResponseInterface::class);
            $underlying->method('getContent')->willReturn('body');
            $underlying->method('getInfo')->willReturn(200);
            $underlying->expects($this->never())->method('getHeaders');
            $response = new TraceableResponse($this->createMock(HttpClientInterface::class), $underlying, $span, $options);

            $this->assertSame('body', $response->getContent());
            unset($response);
        }
    }

    public function testUnavailableHeadersDoNotMaskTransportErrors(): void
    {
        $span = (new Span())->setSampled(true);
        $error = new TransportException('Request failed');
        $underlying = $this->createMock(ResponseInterface::class);
        $underlying->method('getInfo')->willReturn(0);
        $underlying->expects($this->once())->method('getHeaders')->with(false)->willThrowException(new TransportException('Headers unavailable'));
        $underlying->expects($this->once())->method('getContent')->willThrowException($error);
        $response = new TraceableResponse($this->createMock(HttpClientInterface::class), $underlying, $span, new DataCollectionOptions());

        $this->expectExceptionObject($error);
        try {
            $response->getContent();
        } finally {
            $this->assertNotNull($span->getEndTimestamp());
            $this->assertSame([], $span->getData());
            unset($response);
        }
    }

    public function testUnavailableHeadersDoNotPreventCancellation(): void
    {
        $span = (new Span())->setSampled(true);
        $underlying = $this->createMock(ResponseInterface::class);
        $underlying->method('getInfo')->willReturn(0);
        $underlying->expects($this->once())->method('getHeaders')->with(false)->willThrowException(new TransportException('Headers unavailable'));
        $underlying->expects($this->once())->method('cancel');
        $underlying->expects($this->never())->method('getContent');
        $response = new TraceableResponse($this->createMock(HttpClientInterface::class), $underlying, $span, new DataCollectionOptions());

        $response->cancel();

        $this->assertNotNull($span->getEndTimestamp());
        $this->assertSame([], $span->getData());
        unset($response);
    }

    public function testResourceAndCallbackRequestBodiesAreNotRead(): void
    {
        $stream = fopen('php://temp', 'w+');
        $this->assertIsResource($stream);
        fwrite($stream, 'secret');
        fseek($stream, 2);
        try {
            foreach ([$stream, static function (): string { throw new \LogicException('Do not invoke callback bodies'); }] as $body) {
                $underlying = $this->createMock(HttpClientInterface::class);
                $underlying->method('request')->willReturn(new MockResponse());
                $transaction = null;
                $client = $this->client($underlying, ['data_collection' => []], $transaction);
                $response = $client->request('POST', 'https://example.com', ['body' => $body]);
                $this->assertArrayNotHasKey('http.request.body.data', $this->span($transaction)->getData());
                $this->assertSame(2, ftell($stream));
                unset($response);
            }
        } finally {
            fclose($stream);
        }
    }

    public function testSampledResponsesReadHeadersWhenFinished(): void
    {
        $this->assertHeaderReadsOnFinish(1.0, 1);
    }

    public function testUnsampledResponsesDoNotReadHeaders(): void
    {
        $this->assertHeaderReadsOnFinish(0.0, 0);
    }

    private function assertHeaderReadsOnFinish(float $rate, int $expectedReads): void
    {
        $underlyingResponse = $this->createMock(ResponseInterface::class);
        $underlyingResponse->expects($this->never())->method('getContent');
        $underlyingResponse->expects($this->exactly($expectedReads))->method('getHeaders')->with(false)->willReturn([]);
        $underlyingResponse->expects($this->never())->method('toArray');
        $underlyingResponse->expects($this->never())->method('getStatusCode');
        $underlyingResponse->expects($this->once())->method('getInfo')->with('http_code')->willReturn(200);
        $underlying = $this->createMock(HttpClientInterface::class);
        $underlying->method('request')->willReturn($underlyingResponse);
        $transaction = null;
        $client = $this->client($underlying, ['data_collection' => [], 'traces_sample_rate' => $rate], $transaction);
        $response = $client->request('GET', 'https://example.com');
        unset($response);
    }

    public function testExplicitAttributesArePreserved(): void
    {
        $transaction = null;
        $client = $this->client(new MockHttpClient(new MockResponse('{"token":"secret"}', ['response_headers' => [
            'Content-Type: application/json', 'X-Test: automatic', 'Set-Cookie: session=automatic',
        ]])), ['data_collection' => []], $transaction);
        $response = $client->request('GET', 'https://example.com');
        $explicit = [
            'http.response.header.x-test' => null,
            'http.response.header.set_cookie.session' => 'explicit',
            'http.response.body.data' => ['token' => 'explicit'],
        ];
        $span = $this->span($transaction);
        $span->setData($explicit);
        $response->getContent();
        foreach ($explicit as $key => $value) {
            $this->assertSame($value, $span->getData()[$key]);
        }
    }

    public function testRequestCanRemoveDefaultCookieHeader(): void
    {
        $transaction = null;
        $mock = new MockResponse();
        $client = $this->client(new MockHttpClient($mock), ['data_collection' => []], $transaction);
        if (!method_exists($client, 'withOptions')) {
            $this->markTestSkipped('withOptions is not available.');
        }
        $client = $client->withOptions(['headers' => ['Cookie' => 'theme=default']]);
        $response = $client->request('GET', 'https://example.com', ['headers' => ['cOoKiE' => []]]);
        $response->getContent();
        $this->assertArrayNotHasKey('http.request.header.cookie.theme', $this->span($transaction)->getData());
        $this->assertSame([], $mock->getRequestOptions()['normalized_headers']['cookie']);
    }

    public function testClientDefaultsAndRequestOverridesAreCollected(): void
    {
        if (!method_exists(MockHttpClient::class, 'withOptions')) {
            $this->markTestSkipped('withOptions is not available.');
        }
        $defaults = [
            'headers' => ['X-Default' => 'base', 'X-Override' => 'base', 'Cookie' => 'theme=base'],
        ];
        $responses = [new MockResponse(), new MockResponse(), new MockResponse()];
        $transaction = null;
        $client = $this->client((new MockHttpClient($responses))->withOptions($defaults), ['data_collection' => []], $transaction, $defaults);
        $clone = $client->withOptions([
            'headers' => ['x-override' => 'clone'],
        ]);

        $client->request('POST', 'https://example.com')->getContent();
        $clone->request('POST', 'https://example.com')->getContent();
        $clone->request('POST', 'https://example.com', [
            'headers' => ['X-OVERRIDE' => 'request', 'cOoKiE' => []],
        ])->getContent();

        $recorder = $transaction->getSpanRecorder();
        $this->assertNotNull($recorder);
        $spans = $recorder->getSpans();
        foreach (['base', 'clone', 'request'] as $index => $value) {
            $data = $spans[$index + 1]->getData();
            $this->assertSame(['base'], $data['http.request.header.x-default']);
            $this->assertSame([$value], $data['http.request.header.x-override']);
        }
        $this->assertSame(['x-override: clone'], $responses[1]->getRequestOptions()['normalized_headers']['x-override']);
        $this->assertSame('base', $spans[1]->getData()['http.request.header.cookie.theme']);
        $this->assertSame('base', $spans[2]->getData()['http.request.header.cookie.theme']);
        $this->assertArrayNotHasKey('http.request.header.cookie.theme', $spans[3]->getData());
        $this->assertSame([], $responses[2]->getRequestOptions()['normalized_headers']['cookie']);
    }

    public function testQueryCollectionUsesTheDeclaredUrl(): void
    {
        $transaction = null;
        $underlying = new MockHttpClient(new MockResponse(), 'https://example.com');
        $client = $this->client($underlying, ['data_collection' => []], $transaction);
        $response = $client->request('GET', '/?%74oken=secret&search=old&q=a%20b', [
            'query' => ['search' => 'new'],
        ]);

        $this->assertSame('https://example.com/?token=secret&search=new&q=a%20b', $response->getInfo('url'));
        $this->assertSame('%74oken=[Filtered]&search=old&q=a%20b', $this->span($transaction)->getData()['http.query']);
        $response->getContent();
    }

    public function testQueryOptionsWithoutADeclaredQueryAreNotCollected(): void
    {
        $transaction = null;
        $client = $this->client(new MockHttpClient(new MockResponse()), ['data_collection' => []], $transaction);
        $response = $client->request('GET', 'https://example.com', ['query' => ['search' => 'new']]);

        $this->assertSame('https://example.com/?search=new', $response->getInfo('url'));
        $this->assertArrayNotHasKey('http.query', $this->span($transaction)->getData());
        $response->getContent();
    }

    public function testBodyOptionsDoNotInferCollectedContentType(): void
    {
        if (!method_exists(MockHttpClient::class, 'withOptions')) {
            $this->markTestSkipped('withOptions is not available.');
        }
        $defaults = ['json' => ['name' => 'default']];
        $mock = new MockResponse();
        $transaction = null;
        $client = $this->client((new MockHttpClient($mock))->withOptions($defaults), ['data_collection' => []], $transaction, $defaults);
        $client->request('POST', 'https://example.com', [
            'json' => null,
            'body' => ['name' => 'form'],
        ])->getContent();

        $this->assertSame('name=form', $mock->getRequestOptions()['body']);
        $data = $this->span($transaction)->getData();
        $this->assertArrayNotHasKey('http.request.header.content-type', $data);
        $this->assertArrayNotHasKey('http.request.body.data', $data);
    }

    public function testBodyOptionsDoNotAddHeadersToSpan(): void
    {
        $stream = fopen('php://temp', 'w+');
        $this->assertIsResource($stream);
        fwrite($stream, 'upload');
        rewind($stream);

        try {
            foreach ([
                ['json' => ['name' => 'Alice']],
                ['body' => ['name' => 'Alice']],
                ['body' => ['file' => $stream]],
            ] as $options) {
                $transaction = null;
                $mock = new MockResponse();
                $client = $this->client(new MockHttpClient($mock), ['data_collection' => []], $transaction);
                $client->request('POST', 'https://example.com', $options)->getContent();

                $data = $this->span($transaction)->getData();
                $this->assertArrayNotHasKey('http.request.header.content-type', $data);
                $this->assertArrayNotHasKey('http.request.header.content-length', $data);
                $this->assertArrayNotHasKey('http.request.body.data', $data);
            }
        } finally {
            fclose($stream);
        }
    }

    public function testDeclaredContentLengthIsCollected(): void
    {
        $transaction = null;
        $mock = new MockResponse();
        $client = $this->client(new MockHttpClient($mock), ['data_collection' => []], $transaction);
        $client->request('POST', 'https://example.com', [
            'body' => 'hello',
            'headers' => ['Content-Length' => '1', 'Content-Type' => 'text/plain'],
        ])->getContent();

        $data = $this->span($transaction)->getData();
        $this->assertSame(['text/plain'], $data['http.request.header.content-type']);
        $this->assertSame(['1'], $data['http.request.header.content-length']);
    }

    public function testDeclaredContentTypeIsCollected(): void
    {
        $transaction = null;
        $mock = new MockResponse();
        $client = $this->client(new MockHttpClient($mock), ['data_collection' => []], $transaction);
        $client->request('POST', 'https://example.com', [
            'body' => ['name' => 'Alice'],
            'headers' => ['Content-Type' => 'text/plain'],
        ])->getContent();

        $this->assertSame(['text/plain'], $this->span($transaction)->getData()['http.request.header.content-type']);
    }

    public function testResponseHeadersCanBeCollectedAfterAnHttpException(): void
    {
        $transaction = null;
        $body = '{"name":"Bob","token":"secret"}';
        $client = $this->client(new MockHttpClient(new MockResponse($body, [
            'http_code' => 500,
            'response_headers' => ['Content-Type: application/json'],
        ])), ['data_collection' => []], $transaction);
        $response = $client->request('GET', 'https://example.com');
        try {
            $response->getContent();
            $this->fail('Expected the HTTP exception.');
        } catch (HttpExceptionInterface $exception) {
            $span = $this->span($transaction);
            $finishedAt = $span->getEndTimestamp();
            $this->assertNotNull($finishedAt);
            $this->assertSame(['application/json'], $span->getData()['http.response.header.content-type']);
            $this->assertSame($body, $response->getContent(false));
            $this->assertArrayNotHasKey('http.response.body.data', $span->getData());
            $this->assertSame($finishedAt, $span->getEndTimestamp());
        }
    }

    public function testHttpErrorsRetainTheirBehavior(): void
    {
        $transaction = null;
        $client = $this->client(new MockHttpClient(new MockResponse('error', ['http_code' => 500, 'response_headers' => ['X-Response: visible']])), ['data_collection' => []], $transaction);
        $response = $client->request('GET', 'https://example.com');
        try {
            $response->getContent();
            $this->fail('Expected the HTTP exception.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertNotNull($this->span($transaction)->getEndTimestamp());
            $this->assertSame(['visible'], $this->span($transaction)->getData()['http.response.header.x-response']);
        }
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $defaultOptions
     */
    private function client(HttpClientInterface $underlying, array $options, ?Transaction &$transaction, array $defaultOptions = []): TraceableHttpClient
    {
        $sdkClient = $this->createMock(ClientInterface::class);
        $sdkClient->method('getOptions')->willReturn(new Options($options + ['traces_sample_rate' => 1.0]));
        $hub = new Hub($sdkClient);
        $transaction = $hub->startTransaction(TransactionContext::make());
        $hub->setSpan($transaction);

        return new TraceableHttpClient($underlying, $hub, $defaultOptions);
    }

    private function span(Transaction $transaction): Span
    {
        $recorder = $transaction->getSpanRecorder();
        $this->assertNotNull($recorder);

        return $recorder->getSpans()[1];
    }
}
