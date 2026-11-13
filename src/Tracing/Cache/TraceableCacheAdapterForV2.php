<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\Cache;

use Sentry\SentryBundle\Sentry;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\PruneableInterface;
use Symfony\Component\Cache\ResettableInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * This implementation of a cache adapter supports the distributed tracing
 * feature of Sentry.
 *
 * @internal
 */
final class TraceableCacheAdapterForV2 implements AdapterInterface, CacheInterface, PruneableInterface, ResettableInterface
{
    /**
     * @phpstan-use TraceableCacheAdapterTrait<AdapterInterface>
     */
    use TraceableCacheAdapterTrait;

    /**
     * @param Sentry           $sentry
     * @param AdapterInterface $decoratedAdapter The decorated cache adapter
     */
    public function __construct(Sentry $sentry, AdapterInterface $decoratedAdapter)
    {
        $this->sentry = $sentry;
        $this->decoratedAdapter = $decoratedAdapter;
    }

    /**
     * {@inheritdoc}
     *
     * @param mixed[] $metadata
     *
     * @return mixed
     */
    public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null)
    {
        return $this->traceGet($key, $callback, $beta, $metadata);
    }
}
