<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\HttpClient;

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

    public function __construct(HttpClientInterface $client, ResponseInterface $response, ?Span $span)
    {
        $this->client = $client;
        $this->response = $response;
        $this->span = $span;
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

    public function __wakeup()
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
        try {
            return $this->response->getContent($throw);
        } finally {
            $this->finishSpan();
        }
    }

    public function toArray(bool $throw = true): array
    {
        try {
            return $this->response->toArray($throw);
        } finally {
            $this->finishSpan();
        }
    }

    public function cancel(): void
    {
        $this->response->cancel();
        $this->finishSpan();
    }

    /**
     * @internal
     *
     * @param iterable<AbstractTraceableResponse> $responses
     *
     * @return \Generator<AbstractTraceableResponse, ChunkInterface>
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

    private function finishSpan(): void
    {
        if (null === $this->span) {
            return;
        }

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

        $this->span = null;
    }
}
