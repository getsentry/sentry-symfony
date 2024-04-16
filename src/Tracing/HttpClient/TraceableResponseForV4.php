<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\HttpClient;

/**
 * @internal
 */
final class TraceableResponseForV4 extends AbstractTraceableResponse
{
    /**
     * {@inheritdoc}
     */
    public function getInfo(string $type = null): mixed
    {
        return $this->response->getInfo($type);
    }
}
