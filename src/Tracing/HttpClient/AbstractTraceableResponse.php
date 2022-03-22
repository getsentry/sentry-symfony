<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\HttpClient;

use Closure;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\Exception\RedirectionException;
use Symfony\Component\HttpClient\Exception\ServerException;
use Symfony\Component\HttpClient\Response\StreamableInterface;
use Symfony\Component\HttpClient\Response\StreamWrapper;
use Symfony\Component\HttpClient\TraceableHttpClient;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
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
    private $client;

    /**
     * @var Span|null
     */
    private $span;

    public function __construct(HttpClientInterface $client, ResponseInterface $response, ?Span $span)
    {
        $this->client = $client;
        $this->response = $response;
        $this->span = $span;
    }

    public function __sleep(): array
    {
        throw new \BadMethodCallException('Cannot serialize ' . __CLASS__);
    }

    public function __wakeup()
    {
        throw new \BadMethodCallException('Cannot unserialize ' . __CLASS__);
    }

    public function __destruct()
    {
        try {
            if (method_exists($this->response, '__destruct')) {
                $this->response->__destruct();
            }
        } finally {
            $this->finish();
        }
    }

    public function getStatusCode(): int
    {
        return $this->traceFunction('status_code', function () {
            return $this->response->getStatusCode();
        });
    }

    public function getHeaders(bool $throw = true): array
    {
        return $this->traceFunction('headers', function () use ($throw) {
            return $this->response->getHeaders($throw);
        });
    }

    public function getContent(bool $throw = true): string
    {
        try {
            return $this->response->getContent($throw);
        } finally {
            $this->finish();

            if ($throw) {
                $this->checkStatusCode($this->response->getStatusCode());
            }
        }
    }

    public function toArray(bool $throw = true): array
    {
        try {
            return $this->response->toArray($throw);
        } finally {
            $this->finish();

            if ($throw) {
                $this->checkStatusCode($this->response->getStatusCode());
            }
        }
    }

    public function cancel(): void
    {
        $this->response->cancel();
        $this->finish();
    }

    /**
     * Casts the response to a PHP stream resource.
     *
     * @return resource
     *
     * @throws TransportExceptionInterface   When a network error occurs
     * @throws RedirectionExceptionInterface On a 3xx when $throw is true and the "max_redirects" option has been reached
     * @throws ClientExceptionInterface      On a 4xx when $throw is true
     * @throws ServerExceptionInterface      On a 5xx when $throw is true
     */
    public function toStream(bool $throw = true)
    {
        if ($throw) {
            // Ensure headers arrived
            $this->response->getHeaders(true);
        }

        if ($this->response instanceof StreamableInterface) {
            return $this->response->toStream(false);
        }

        return StreamWrapper::createResource($this->response, $this->client);
    }

    /**
     * @internal
     *
     * @return \Generator<AbstractTraceableResponse, ChunkInterface>
     */
    public static function stream(HttpClientInterface $client, iterable $responses, ?float $timeout): \Generator
    {
        $wrappedResponses = [];
        /** @var \SplObjectStorage<ResponseInterface, AbstractTraceableResponse> $traceableMap */
        $traceableMap = new \SplObjectStorage();

        foreach ($responses as $r) {
            if (!$r instanceof self) {
                throw new \TypeError(sprintf('"%s::stream()" expects parameter 1 to be an iterable of TraceableResponse objects, "%s" given.', TraceableHttpClient::class, get_debug_type($r)));
            }

            $traceableMap[$r->response] = $r;
            $wrappedResponses[] = $r->response;
            if ($r->span) {
                $spanContext = new SpanContext();
                $spanContext->setOp('stream');

                $r->span = $r->span->startChild($spanContext);
            }
        }

        foreach ($client->stream($wrappedResponses, $timeout) as $r => $chunk) {
            if ($traceableMap[$r]->span) {
                $traceableMap[$r]->span->finish();
            }

            yield $traceableMap[$r] => $chunk;
        }
    }

    private function checkStatusCode(int $code): void
    {
        if (500 <= $code) {
            throw new ServerException($this);
        }

        if (400 <= $code) {
            throw new ClientException($this);
        }

        if (300 <= $code) {
            throw new RedirectionException($this);
        }
    }

    private function finish(): void
    {
        if (null !== $this->span) {
            $this->span->finish();
            $this->span = null;
        }
    }

    /**
     * @phpstan-template TResult
     *
     * @phpstan-param Closure(): TResult $callback
     *
     * @phpstan-return TResult
     */
    private function traceFunction(string $spanOperation, Closure $callback)
    {
        $span = $this->span;
        if (null !== $span) {
            $spanContext = new SpanContext();
            $spanContext->setOp($spanOperation);

            $span = $span->startChild($spanContext);
        }

        try {
            return $callback();
        } finally {
            if (null !== $span) {
                $span->finish();
            }
        }
    }
}
