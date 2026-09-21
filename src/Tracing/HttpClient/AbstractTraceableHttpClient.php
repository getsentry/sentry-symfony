<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\HttpClient;

use GuzzleHttp\Psr7\Uri;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Sentry\DataCollection\DataCollectionOptions;
use Sentry\DataCollection\DataCollectionPolicy;
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
     * Request defaults that are not visible in individual request arguments.
     *
     * @var array<string, mixed>
     */
    protected $defaultRequestOptions = ['headers' => []];

    /**
     * @param array<string, mixed> $defaultOptions
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
        $parentSpan = $this->hub->getSpan();
        $policy = DataCollectionPolicy::fromHub($this->hub);

        if (null === $parentSpan) {
            if (self::shouldAttachTracingHeaders($policy->getOptions(), $uri)) {
                $headers['baggage'] = getBaggage();
                $headers['sentry-trace'] = getTraceparent();
            }

            $options['headers'] = $headers;

            return new TraceableResponse($this->client, $this->client->request($method, $url, $options), null, $policy);
        }

        $partialUri = Uri::fromParts([
            'scheme' => $uri->getScheme(),
            'host' => $uri->getHost(),
            'port' => $uri->getPort(),
            'path' => $uri->getPath(),
        ]);
        $contextData = [
            'http.url' => (string) $partialUri,
            'http.request.method' => $method,
        ];
        $contextData += HttpDataCollector::collectQueryData($policy, $uri->getQuery());
        if ('' !== $uri->getFragment()) {
            $contextData['http.fragment'] = $uri->getFragment();
        }

        $context = SpanContext::make()
            ->setOp('http.client')
            ->setOrigin('auto.http.client')
            ->setDescription($method . ' ' . (string) $partialUri)
            ->setData($contextData);
        $childSpan = $parentSpan->startChild($context);

        if (self::shouldAttachTracingHeaders($policy->getOptions(), $uri)) {
            $headers['baggage'] = $childSpan->toBaggage();
            $headers['sentry-trace'] = $childSpan->toTraceparent();
        }

        $options['headers'] = $headers;
        $this->collectRequestData($childSpan, $policy, $options);

        return new TraceableResponse(
            $this->client,
            $this->client->request($method, $url, $options),
            $childSpan,
            $policy
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private function collectRequestData(Span $span, DataCollectionPolicy $policy, array $options): void
    {
        if (!$span->getSampled()) {
            return;
        }

        $requestOptions = $this->resolveRequestOptions($options);
        /** @var array<array-key, string[]> $headers */
        $headers = $requestOptions['headers'];
        $hasAuthenticationOption = ($requestOptions['auth_basic'] ?? false) || ($requestOptions['auth_bearer'] ?? false);
        if ($hasAuthenticationOption && !($headers['authorization'] ?? false)) {
            $headers['authorization'] = [KeyValueDataFilter::FILTERED_VALUE];
        }

        $data = HttpDataCollector::collectRequestData($policy, $headers);
        $body = $requestOptions['json'] ?? $requestOptions['body'] ?? null;
        $contentType = isset($requestOptions['json']) ? '' : ($headers['content-type'][0] ?? '');
        if (\is_string($body) || \is_array($body)) {
            $data += HttpDataCollector::collectBodyData($policy, DataCollectionOptions::HTTP_BODY_OUTGOING_REQUEST, $body, $contentType);
        }

        $span->setData(array_diff_key($data, $span->getData()));
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
        $options['headers'] = HttpHeaderNormalizer::normalize($headers) + $this->defaultRequestOptions['headers'];

        return $options + $this->defaultRequestOptions;
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

    private static function shouldAttachTracingHeaders(?Options $options, Uri $uri): bool
    {
        if (null === $options) {
            return false;
        }

        return null === $options->getTracePropagationTargets()
            || \in_array($uri->getHost(), $options->getTracePropagationTargets());
    }
}
