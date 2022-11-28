<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\HttpClient;

/**
 * @internal
 */
final class TraceableHttpClientForV6 extends AbstractTraceableHttpClient
{
    /**
     * {@inheritdoc}
     */
    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->client = $this->client->withOptions($options);

        return $clone;
    }
}
