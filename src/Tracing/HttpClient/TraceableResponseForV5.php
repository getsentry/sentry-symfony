<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\HttpClient;

use Symfony\Component\HttpClient\Response\StreamableInterface;

/**
 * @internal
 */
class TraceableResponseForV5 extends AbstractTraceableResponse implements StreamableInterface
{
    /**
     * @return mixed An array of all available info, or one of them when $type is
     *               provided, or null when an unsupported type is requested
     */
    public function getInfo(string $type = null)
    {
        return $this->response->getInfo($type);
    }
}
