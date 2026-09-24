<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\HttpClient;

use Sentry\DataCollection\DataCollectionPolicy;
use Sentry\DataCollection\HttpBodyCollector;
use Sentry\DataCollection\HttpCookieCollector;
use Sentry\DataCollection\HttpCookieParser;
use Sentry\DataCollection\HttpHeaderCollector;
use Sentry\DataCollection\HttpMessageType;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanStatus;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * @internal
 */
abstract class AbstractTraceableResponse implements ResponseInterface
{
    /**
     * @var ResponseInterface
     */
    protected $response;

    /**
     * @var HttpClientInterface
     */
    protected $client;

    /**
     * @var Span|null
     */
    protected $span;

    /**
     * @var DataCollectionPolicy|null
     */
    private $policy;

    /**
     * @var bool
     */
    private $responseHeadersCollected = false;

    /**
     * @var bool
     */
    private $responseContentCollected = false;

    public function __construct(HttpClientInterface $client, ResponseInterface $response, ?Span $span, ?DataCollectionPolicy $policy = null)
    {
        $this->client = $client;
        $this->response = $response;
        $this->span = $span;
        $this->policy = $policy;
    }

    public function __destruct()
    {
        try {
            if (method_exists($this->response, '__destruct')) {
                $this->response->__destruct();
            }
        } finally {
            $this->finishSpan();
        }
    }

    public function __sleep(): array
    {
        throw new \BadMethodCallException('Serializing instances of this class is forbidden.');
    }

    public function __wakeup(): void
    {
        throw new \BadMethodCallException('Unserializing instances of this class is forbidden.');
    }

    public function getStatusCode(): int
    {
        return $this->response->getStatusCode();
    }

    public function getHeaders(bool $throw = true): array
    {
        return $this->response->getHeaders($throw);
    }

    public function getContent(bool $throw = true): string
    {
        $content = null;

        try {
            return $content = $this->response->getContent($throw);
        } finally {
            $this->finishSpan($content);
        }
    }

    public function toArray(bool $throw = true): array
    {
        $content = null;

        try {
            return $content = $this->response->toArray($throw);
        } finally {
            $this->finishSpan($content);
        }
    }

    public function cancel(): void
    {
        $this->response->cancel();
        $this->finishSpan();
    }

    /**
     * @param iterable<AbstractTraceableResponse> $responses
     *
     * @return \Generator<AbstractTraceableResponse, ChunkInterface>
     *
     * @internal
     */
    public static function stream(HttpClientInterface $client, iterable $responses, ?float $timeout): \Generator
    {
        /** @var \SplObjectStorage<ResponseInterface, AbstractTraceableResponse> $traceableMap */
        $traceableMap = new \SplObjectStorage();
        $wrappedResponses = [];

        foreach ($responses as $response) {
            if (!$response instanceof self) {
                throw new \TypeError(\sprintf('"%s::stream()" expects parameter 1 to be an iterable of TraceableResponse objects, "%s" given.', TraceableHttpClient::class, get_debug_type($response)));
            }

            $traceableMap[$response->response] = $response;
            $wrappedResponses[] = $response->response;
        }

        foreach ($client->stream($wrappedResponses, $timeout) as $response => $chunk) {
            $traceableResponse = $traceableMap[$response];
            $traceableResponse->finishSpan();

            yield $traceableResponse => $chunk;
        }
    }

    /**
     * @param array<array-key, mixed>|string|null $content
     */
    private function finishSpan($content = null): void
    {
        if (null === $this->span) {
            return;
        }

        if (null === $this->span->getEndTimestamp()) {
            // We finish the span (which means setting the span end timestamp) first
            // to ensure the measured time is as close as possible to the duration of
            // the HTTP request
            $this->span->finish();

            /** @var int $statusCode */
            $statusCode = $this->response->getInfo('http_code');

            // If the returned status code is 0, it means that this info isn't available
            // yet (e.g. an error happened before the request was sent), hence we cannot
            // determine what happened.
            if (0 === $statusCode) {
                $this->span->setStatus(SpanStatus::unknownError());
            } else {
                $this->span->setStatus(SpanStatus::createFromHttpStatusCode($statusCode));
            }
        }

        $span = $this->span;
        $policy = $this->policy;
        if (null === $policy || !$span->getSampled()) {
            $this->span = null;

            return;
        }

        // The span is kept, as the headers can be received after it finished when streaming,
        // and the content is only collected once the application reads it
        if (!$this->responseHeadersCollected) {
            $this->collectResponseHeaders($span, $policy);
        }

        if (null !== $content && !$this->responseContentCollected) {
            $this->collectResponseContent($span, $policy, $content);
        }
    }

    private function collectResponseHeaders(Span $span, DataCollectionPolicy $policy): void
    {
        // The headers are not received yet
        if (0 === $this->response->getInfo('http_code')) {
            return;
        }

        $this->responseHeadersCollected = true;
        $responseHeaders = $this->getResponseHeaders();
        $responseCookies = HttpCookieParser::parseSetCookieHeaders($responseHeaders['set-cookie'] ?? []);
        $spanData = [];

        foreach (HttpHeaderCollector::collect($policy, HttpMessageType::incomingResponse(), $responseHeaders) ?? [] as $name => $values) {
            $spanData['http.response.header.' . $name] = $values;
        }

        $cookies = HttpCookieCollector::collectGroupedPairs($policy, HttpMessageType::incomingResponse(), $responseCookies);
        foreach (\is_array($cookies) ? $cookies : [] as $name => $value) {
            $spanData['http.response.header.set_cookie.' . $name] = $value;
        }

        $span->setData($spanData);
    }

    /**
     * @param array<array-key, mixed>|string $content The content returned by getContent() or toArray()
     */
    private function collectResponseContent(Span $span, DataCollectionPolicy $policy, $content): void
    {
        $this->responseContentCollected = true;
        $responseBody = HttpBodyCollector::collect($policy, HttpMessageType::incomingResponse(), $content, $this->getResponseHeaders()['content-type'][0] ?? '');

        if (null === $responseBody) {
            return;
        }

        $span->setData(['http.response.body.data' => $responseBody]);
    }

    /**
     * Reads the headers of the last response from the info, which unlike
     * {@see getHeaders()} neither waits for the response nor throws.
     *
     * @return array<string, string[]>
     */
    private function getResponseHeaders(): array
    {
        $headers = [];
        $headerLines = $this->response->getInfo('response_headers');
        if (!\is_array($headerLines)) {
            return $headers;
        }

        foreach ($headerLines as $headerLine) {
            if (!\is_string($headerLine)) {
                continue;
            }

            // Splits a header line like "Content-Type: application/json; charset=utf-8" into its name and value.
            $header = explode(':', $headerLine, 2);

            if (2 !== \count($header)) {
                $headers = [];

                continue;
            }

            $headers[strtolower(trim($header[0]))][] = trim($header[1]);
        }

        return $headers;
    }
}
