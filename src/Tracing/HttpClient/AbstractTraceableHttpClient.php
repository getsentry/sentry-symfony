<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\HttpClient;

use GuzzleHttp\Psr7\Uri;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Sentry\State\HubInterface;
use Sentry\Tracing\SpanContext;
use Symfony\Component\HttpClient\Response\ResponseStream;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Symfony\Contracts\Service\ResetInterface;

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
        $span = null;
        $parent = $this->hub->getSpan();

        if (null !== $parent) {
            $headers = $options['headers'] ?? [];
            $headers['sentry-trace'] = $parent->toTraceparent();

            $uri = new Uri($url);
            $partialUri = Uri::fromParts([
                    'scheme' => $uri->getScheme(),
                    'host' => $uri->getHost(),
                    'port' => $uri->getPort(),
                    'path' => $uri->getPath(),
                ]);

            // Check if the request destination is allow listed in the trace_propagation_targets option.
            $client = $this->hub->getClient();
            if (null !== $client) {
                $sdkOptions = $client->getOptions();

                if (\in_array($uri->getHost(), $sdkOptions->getTracePropagationTargets())) {
                    $headers['baggage'] = $parent->toBaggage();
                }
            }

            $options['headers'] = $headers;

            $context = new SpanContext();
            $context->setOp('http.client');
            $context->setDescription($method . ' ' . (string) $partialUri);
            $context->setTags([
                'http.method' => $method,
                'http.url' => (string) $partialUri,
            ]);
            $context->setData([
                'http.query' => $uri->getQuery(),
                'http.fragment' => $uri->getFragment(),
            ]);

            $span = $parent->startChild($context);
        }

        return new TraceableResponse($this->client, $this->client->request($method, $url, $options), $span);
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
}
