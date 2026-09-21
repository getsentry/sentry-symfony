<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\HttpClient;

use Sentry\DataCollection\DataCollectionOptions;
use Sentry\DataCollection\DataCollectionPolicy;
use Sentry\DataCollection\HttpBodyCollector;
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
     * Kept after timing finishes because response bodies can be materialized later.
     *
     * @var Span|null
     */
    protected $span;

    /**
     * Retained so delayed response collection observes live option updates.
     *
     * @var DataCollectionPolicy
     */
    private $policy;

    /**
     * @var bool
     */
    private $timingFinished = false;

    /**
     * @var bool
     */
    private $responseDataCollected = false;

    /**
     * @var string
     */
    private $contentType = '';

    public function __construct(HttpClientInterface $client, ResponseInterface $response, ?Span $span, ?DataCollectionPolicy $policy = null)
    {
        $this->client = $client;
        $this->response = $response;
        $this->span = $span;
        $this->policy = $policy ?? DataCollectionPolicy::fromOptions(null);
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
            $body = $this->response->getContent($throw);
        } finally {
            $this->finishSpan();
        }

        $this->collectBody($body);

        return $body;
    }

    public function toArray(bool $throw = true): array
    {
        try {
            $body = $this->response->toArray($throw);
        } finally {
            $this->finishSpan();
        }

        $this->collectBody($body);

        return $body;
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
     * @param string|array<array-key, mixed> $body
     */
    private function collectBody($body): void
    {
        $span = $this->span;
        if (null === $span || !$span->getSampled()
            || \array_key_exists('http.response.body.data', $span->getData())
            || 0 === HttpBodyCollector::getMaxBodyLength($this->policy, DataCollectionOptions::HTTP_BODY_INCOMING_RESPONSE)) {
            return;
        }

        $this->collectResponseData();
        $data = HttpDataCollector::collectBodyData($this->policy, DataCollectionOptions::HTTP_BODY_INCOMING_RESPONSE, $body, $this->contentType);
        $span->setData(array_diff_key($data, $span->getData()));
    }

    private function finishSpan(): void
    {
        $span = $this->span;
        if (null === $span) {
            return;
        }

        if (!$this->timingFinished) {
            $this->timingFinished = true;
            $span->finish();

            /** @var int $statusCode */
            $statusCode = $this->response->getInfo('http_code');
            $span->setStatus(0 === $statusCode
                ? SpanStatus::unknownError()
                : SpanStatus::createFromHttpStatusCode($statusCode));
        }

        $this->collectResponseData();
    }

    private function collectResponseData(): void
    {
        $span = $this->span;
        if ($this->responseDataCollected || null === $span || !$span->getSampled()) {
            return;
        }

        if ($this->policy->isLegacyMode()) {
            return;
        }

        $this->responseDataCollected = true;
        try {
            $headers = $this->response->getHeaders(false);
        } catch (TransportExceptionInterface $exception) {
            return;
        }

        $this->contentType = $headers['content-type'][0] ?? '';
        $data = HttpDataCollector::collectResponseData($this->policy, $headers);
        $span->setData(array_diff_key($data, $span->getData()));
    }
}
