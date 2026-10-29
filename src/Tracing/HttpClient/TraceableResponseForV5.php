<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\HttpClient;

use Symfony\Component\HttpClient\Response\StreamableInterface;

/**
 * @internal
 */
final class TraceableResponseForV5 extends AbstractTraceableResponse implements StreamableInterface
{
    /**
     * {@inheritdoc}
     *
     * @return mixed
     */
    public function getInfo(?string $type = null)
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
