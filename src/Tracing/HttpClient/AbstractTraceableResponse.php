<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\HttpClient;

use Sentry\DataCollection\DataCollectionOptions;
use Sentry\DataCollection\HttpDataCollector;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanStatus;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
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
     * @var DataCollectionOptions|null
     */
    private $collectionOptions;

    public function __construct(HttpClientInterface $client, ResponseInterface $response, ?Span $span, ?DataCollectionOptions $options = null)
    {
        $this->client = $client;
        $this->response = $response;
        $this->span = $span;
        $this->collectionOptions = $options;
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

    private function finishSpan(): void
    {
        $span = $this->span;
        if (null === $span) {
            return;
        }

        $span->finish();

        /** @var int $statusCode */
        $statusCode = $this->response->getInfo('http_code');

        if (0 === $statusCode) {
            $span->setStatus(SpanStatus::unknownError());
        } else {
            $span->setStatus(SpanStatus::createFromHttpStatusCode($statusCode));
        }

        $this->span = null;

        if (!$span->getSampled() || null === $this->collectionOptions) {
            return;
        }

        try {
            $headers = $this->response->getHeaders(false);
        } catch (TransportExceptionInterface $exception) {
            return;
        }
        HttpDataCollector::setMissingSpanData($span, HttpDataCollector::collectResponseData($this->collectionOptions, $headers));
    }
}
