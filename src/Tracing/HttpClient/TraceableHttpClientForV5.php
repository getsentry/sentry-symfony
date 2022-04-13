<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\HttpClient;

final class TraceableHttpClientForV5 extends AbstractTraceableHttpClient
{
    /**
     * {@inheritdoc}
     */
    public function withOptions(array $options): self
    {
        $clone = clone $this;
        $clone->client = $this->client->withOptions($options);

        return $clone;
    }
}
