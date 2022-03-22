<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\HttpClient;

use Symfony\Component\HttpClient\Response\StreamableInterface;

/**
 * @internal
 */
class TraceableResponseForV6 extends AbstractTraceableResponse implements StreamableInterface
{
    /**
     * @return mixed An array of all available info, or one of them when $type is
     *               provided, or null when an unsupported type is requested
     */
    public function getInfo(string $type = null): mixed
    {
        return $this->response->getInfo($type);
    }
}
