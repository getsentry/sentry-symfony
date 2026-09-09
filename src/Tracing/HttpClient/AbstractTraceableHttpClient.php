<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\HttpClient;

use GuzzleHttp\Psr7\Uri;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Sentry\DataCollection\DataCollectionOptions;
use Sentry\DataCollection\HttpDataCollector;
use Sentry\DataCollection\HttpHeaderNormalizer;
use Sentry\DataCollection\KeyValueDataFilter;
use Sentry\Options;
use Sentry\State\HubInterface;
use Sentry\Tracing\Span;
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

    /**
     * HTTP clients can get a list of headers that are applied to requests which are not visible
     * in the request object. We store a copy of those extra headers here so we can apply them for
     * data collection.
     *
     * @var array<string, mixed>
     */
    protected $defaultRequestOptions = ['headers' => []];

    /**
     * @param array<string, mixed> $defaultOptions Collection inputs matching the underlying client's defaults
     */
    public function __construct(HttpClientInterface $client, HubInterface $hub, array $defaultOptions = [])
    {
        $this->client = $client;
        $this->hub = $hub;
        $this->defaultRequestOptions = $this->resolveRequestOptions($defaultOptions);
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
        $sdkOptions = null === $client ? null : $client->getOptions();

        if (null === $span) {
            if (self::shouldAttachTracingHeaders($sdkOptions, $uri)) {
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

        $context = SpanContext::make()
            ->setOp('http.client')
            ->setOrigin('auto.http.client')
            ->setDescription($method . ' ' . (string)$partialUri);

        $contextData = [
            'http.url' => (string)$partialUri,
            'http.request.method' => $method,
        ];
        $dataCollection = DataCollectionOptions::fromOptions($sdkOptions);
        $contextData += HttpDataCollector::collectQueryData($dataCollection, $uri->getQuery());
        if ('' !== $uri->getFragment()) {
            $contextData['http.fragment'] = $uri->getFragment();
        }
        $context->setData($contextData);

        $childSpan = $span->startChild($context);

        if (self::shouldAttachTracingHeaders($sdkOptions, $uri)) {
            $headers['baggage'] = $childSpan->toBaggage();
            $headers['sentry-trace'] = $childSpan->toTraceparent();
        }

        $options['headers'] = $headers;

        $this->collectRequestData($childSpan, $dataCollection, $options);

        return new TraceableResponse($this->client, $this->client->request($method, $url, $options), $childSpan, $dataCollection);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function collectRequestData(Span $span, ?DataCollectionOptions $dataCollection, array $options): void
    {
        if (!$span->getSampled()) {
            return;
        }

        $headers = $this->prepareRequestHeaders($options);
        $spanData = HttpDataCollector::collectRequestData($dataCollection, $headers);
        HttpDataCollector::setMissingSpanData($span, $spanData);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    protected function resolveRequestOptions(array $options): array
    {
        /** @var array<array-key, mixed> $headers */
        $headers = $options['headers'] ?? [];
        $normalizedHeaders = HttpHeaderNormalizer::normalize($headers);

        $options['headers'] = $normalizedHeaders + $this->defaultRequestOptions['headers'];

        return $options + $this->defaultRequestOptions;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<array-key, string[]>
     */
    private function prepareRequestHeaders(array $options): array
    {
        $requestOptions = $this->resolveRequestOptions($options);
        /** @var array<array-key, string[]> $headers */
        $headers = $requestOptions['headers'];

        if (isset($requestOptions['auth_basic']) || isset($requestOptions['auth_bearer'])) {
            $headers += ['authorization' => [KeyValueDataFilter::FILTERED_VALUE]];
        }

        return $headers;
    }

    /**
     * {@inheritdoc}
     */
    public function stream($responses, ?float $timeout = null): ResponseStreamInterface
    {
        if ($responses instanceof AbstractTraceableResponse) {
            $responses = [$responses];
        } elseif (!is_iterable($responses)) {
            throw new \TypeError(\sprintf('"%s()" expects parameter 1 to be an iterable of TraceableResponse objects, "%s" given.', __METHOD__, get_debug_type($responses)));
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

    private static function shouldAttachTracingHeaders(?Options $sdkOptions, Uri $uri): bool
    {
        if (null !== $sdkOptions) {
            // Check if the request destination is allow listed in the trace_propagation_targets option.
            if (
                null === $sdkOptions->getTracePropagationTargets()
                || \in_array($uri->getHost(), $sdkOptions->getTracePropagationTargets())
            ) {
                return true;
            }
        }

        return false;
    }
}
