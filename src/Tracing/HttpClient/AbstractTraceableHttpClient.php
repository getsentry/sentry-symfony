<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\HttpClient;

use GuzzleHttp\Psr7\Uri;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Sentry\ClientInterface;
use Sentry\State\HubInterface;
use Sentry\Tracing\SpanContext;
use Symfony\Component\HttpClient\Response\ResponseStream;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Symfony\Contracts\Service\ResetInterface;

use function Sentry\getBaggage;
use function Sentry\getTraceparent;

/**
 * This is an implementation of the {@see HttpClientInterface} that decorates
 * an existing http client to support distributed tracing capabilities.
 *
 * @internal
 */
abstract class AbstractTraceableHttpClient implements HttpClientInterface, ResetInterface, LoggerAwareInterface
{
    /**
     * @var HttpClientInterface
     */
    protected $client;

    /**
     * @var HubInterface
     */
    protected $hub;

    public function __construct(HttpClientInterface $client, HubInterface $hub)
    {
        $this->client = $client;
        $this->hub = $hub;
    }

    /**
     * {@inheritdoc}
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $uri = new Uri($url);
        $headers = $options['headers'] ?? [];

        $span = $this->hub->getSpan();
        $client = $this->hub->getClient();

        if (null === $span) {
            if (self::shouldAttachTracingHeaders($client, $uri)) {
                $headers['baggage'] = getBaggage();
                $headers['sentry-trace'] = getTraceparent();
            }

            $options['headers'] = $headers;

            return new TraceableResponse($this->client, $this->client->request($method, $url, $options), $span);
        }

        $partialUri = Uri::fromParts([
            'scheme' => $uri->getScheme(),
            'host' => $uri->getHost(),
            'port' => $uri->getPort(),
            'path' => $uri->getPath(),
        ]);

        $context = new SpanContext();
        $context->setOp('http.client');
        $context->setDescription($method . ' ' . (string) $partialUri);
        $context->setTags([
            'http.method' => $method,
            'http.url' => (string) $partialUri,
        ]);
        $context->setData([
            'http.url' => (string) $partialUri,
            'http.request.method' => $method,
            'http.query' => $uri->getQuery(),
            'http.fragment' => $uri->getFragment(),
        ]);

        $childSpan = $span->startChild($context);

        if (self::shouldAttachTracingHeaders($client, $uri)) {
            $headers['baggage'] = $childSpan->toBaggage();
            $headers['sentry-trace'] = $childSpan->toTraceparent();
        }

        $options['headers'] = $headers;

        return new TraceableResponse($this->client, $this->client->request($method, $url, $options), $childSpan);
    }

    /**
     * {@inheritdoc}
     */
    public function stream($responses, float $timeout = null): ResponseStreamInterface
    {
        if ($responses instanceof AbstractTraceableResponse) {
            $responses = [$responses];
        } elseif (!is_iterable($responses)) {
            throw new \TypeError(sprintf('"%s()" expects parameter 1 to be an iterable of TraceableResponse objects, "%s" given.', __METHOD__, get_debug_type($responses)));
        }

        return new ResponseStream(AbstractTraceableResponse::stream($this->client, $responses, $timeout));
    }

    public function reset(): void
    {
        if ($this->client instanceof ResetInterface) {
            $this->client->reset();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function setLogger(LoggerInterface $logger): void
    {
        if ($this->client instanceof LoggerAwareInterface) {
            $this->client->setLogger($logger);
        }
    }

    private static function shouldAttachTracingHeaders(?ClientInterface $client, Uri $uri): bool
    {
        if (null !== $client) {
            $sdkOptions = $client->getOptions();

            // Check if the request destination is allow listed in the trace_propagation_targets option.
            if (
                null !== $sdkOptions->getTracePropagationTargets() &&
                // Due to BC, we treat an empty array (the default) as all hosts are allow listed
                (
                    [] === $sdkOptions->getTracePropagationTargets() ||
                    \in_array($uri->getHost(), $sdkOptions->getTracePropagationTargets())
                )
            ) {
                return true;
            }
        }

        return false;
    }
}
