<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\HttpClient;

use Symfony\Component\HttpClient\Response\StreamableInterface;

/**
 * @internal
 */
final class TraceableResponseForV6 extends AbstractTraceableResponse implements StreamableInterface
{
    /**
     * {@inheritdoc}
     */
    public function getInfo(?string $type = null): mixed
    {
        return $this->response->getInfo($type);
    }

    /**
     * {@inheritdoc}
     */
    public function toStream(bool $throw = true)
    {
        $stream = $this->response->toStream($throw);
        $this->streamed = true;

        return $stream;
    }
}
