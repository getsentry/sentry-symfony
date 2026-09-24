<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\HttpClient;

use GuzzleHttp\Psr7\Uri;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Sentry\DataCollection\DataCollectionPolicy;
use Sentry\DataCollection\HttpBodyCollector;
use Sentry\DataCollection\HttpCookieCollector;
use Sentry\DataCollection\HttpCookieParser;
use Sentry\DataCollection\HttpHeaderCollector;
use Sentry\DataCollection\HttpMessageType;
use Sentry\DataCollection\HttpUrlCollector;
use Sentry\Options;
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

    /**
     * @var array<string, string[]>
     */
    protected $defaultHeaders;

    /**
     * @param array<array-key, mixed> $defaultHeaders
     */
    public function __construct(HttpClientInterface $client, HubInterface $hub, array $defaultHeaders = [])
    {
        $this->client = $client;
        $this->hub = $hub;
        $this->defaultHeaders = self::normalizeHeaders($defaultHeaders);
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
        $sdkOptions = null !== $client ? $client->getOptions() : null;

        if (null === $span) {
            if (self::shouldAttachTracingHeaders($sdkOptions, $uri)) {
                $headers['baggage'] = getBaggage();
                $headers['sentry-trace'] = getTraceparent();
            }

            $options['headers'] = $headers;

            return new TraceableResponse($this->client, $this->client->request($method, $url, $options), $span);
        }

        $policy = DataCollectionPolicy::fromOptions($sdkOptions);
        $partialUri = Uri::fromParts([
            'scheme' => $uri->getScheme(),
            'host' => $uri->getHost(),
            'port' => $uri->getPort(),
            'path' => $uri->getPath(),
        ]);

        $context = SpanContext::make()
            ->setOp('http.client')
            ->setOrigin('auto.http.client')
            ->setDescription($method . ' ' . (string) $partialUri);

        $contextData = [
            'http.url' => (string) $partialUri,
            'http.request.method' => $method,
        ];

        $queryString = HttpUrlCollector::collectQueryString($policy, $uri->getQuery());
        if (null !== $queryString) {
            $contextData['http.query'] = $queryString;
        }

        if ('' !== $uri->getFragment()) {
            $contextData['http.fragment'] = $uri->getFragment();
        }

        $fullUrl = HttpUrlCollector::collect($policy, HttpMessageType::outgoingRequest(), $uri);
        if (null !== $fullUrl) {
            $contextData['url.full'] = $fullUrl;
        }

        $context->setData($contextData);

        $childSpan = $span->startChild($context);

        // The legacy options collect nothing from outgoing requests and their responses
        $shouldCollectData = $childSpan->getSampled() && !$policy->isLegacyMode();

        if ($shouldCollectData) {
            // Headers added by the HTTP client itself, e.g. by the `auth_bearer` or `json` options, are not collected
            $requestHeaders = self::normalizeHeaders($headers) + $this->defaultHeaders;
            $requestCookies = HttpCookieParser::parseCookieHeaders($requestHeaders['cookie'] ?? []);
            $spanData = [];

            foreach (HttpHeaderCollector::collect($policy, HttpMessageType::outgoingRequest(), $requestHeaders) ?? [] as $name => $values) {
                $spanData['http.request.header.' . $name] = implode(', ', $values);
            }

            $cookies = HttpCookieCollector::collectGroupedPairs($policy, HttpMessageType::outgoingRequest(), $requestCookies);
            foreach (\is_array($cookies) ? $cookies : [] as $name => $value) {
                $spanData['http.request.header.cookie.' . $name] = $value;
            }

            $requestBody = self::collectRequestBody($policy, $options, $requestHeaders);
            if (null !== $requestBody) {
                $spanData['http.request.body.data'] = $requestBody;
            }

            $childSpan->setData($spanData);
        }

        if (self::shouldAttachTracingHeaders($sdkOptions, $uri)) {
            $headers['baggage'] = $childSpan->toBaggage();
            $headers['sentry-trace'] = $childSpan->toTraceparent();
        }

        $options['headers'] = $headers;

        return new TraceableResponse($this->client, $this->client->request($method, $url, $options), $childSpan, $shouldCollectData ? $policy : null);
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

    /**
     * @param mixed $headers
     *
     * @return array<string, string[]>
     */
    protected static function normalizeHeaders($headers): array
    {
        $normalizedHeaders = [];
        if (!is_iterable($headers)) {
            return $normalizedHeaders;
        }

        /** @var mixed $values */
        foreach ($headers as $name => $values) {
            if (\is_object($values) && method_exists($values, '__toString')) {
                $values = (string) $values;
            }

            if (\is_int($name)) {
                if (!\is_string($values)) {
                    continue;
                }

                $headerLine = explode(':', $values, 2);

                if (2 !== \count($headerLine)) {
                    continue;
                }

                [$name, $values] = $headerLine;
                $values = [ltrim($values)];
            } elseif (!is_iterable($values)) {
                $values = [$values];
            }

            $name = strtolower((string) $name);
            $normalizedHeaders[$name] = [];

            /** @var mixed $value */
            foreach ($values as $value) {
                if (\is_scalar($value) || (\is_object($value) && method_exists($value, '__toString'))) {
                    $normalizedHeaders[$name][] = (string) $value;
                }
            }
        }

        return $normalizedHeaders;
    }

    /**
     * @param array<array-key, mixed> $options
     * @param array<string, string[]> $requestHeaders
     *
     * @return array<array-key, mixed>|string|null
     */
    private static function collectRequestBody(DataCollectionPolicy $policy, array $options, array $requestHeaders)
    {
        if (isset($options['json'])) {
            return HttpBodyCollector::collect($policy, HttpMessageType::outgoingRequest(), json_encode($options['json']), 'application/json');
        }

        $body = $options['body'] ?? null;
        if (!\is_string($body) && !\is_array($body)) {
            return null;
        }

        return HttpBodyCollector::collect($policy, HttpMessageType::outgoingRequest(), $body, $requestHeaders['content-type'][0] ?? '');
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
