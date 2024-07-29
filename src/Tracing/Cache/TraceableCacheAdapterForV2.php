<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\Cache;

use Sentry\State\HubInterface;
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
     * @param HubInterface     $hub              The current hub
     * @param AdapterInterface $decoratedAdapter The decorated cache adapter
     */
    public function __construct(HubInterface $hub, AdapterInterface $decoratedAdapter)
    {
        $this->hub = $hub;
        $this->decoratedAdapter = $decoratedAdapter;
    }

    /**
     * {@inheritdoc}
     *
     * @param mixed[] $metadata
     *
     * @return mixed
     */
    public function get(string $key, callable $callback, float $beta = null, array &$metadata = null)
    {
        return $this->traceFunction('cache.get_item', function () use ($key, $callback, $beta, &$metadata) {
            if (!$this->decoratedAdapter instanceof CacheInterface) {
                throw new \BadMethodCallException(\sprintf('The %s::get() method is not supported because the decorated adapter does not implement the "%s" interface.', self::class, CacheInterface::class));
            }

            return $this->decoratedAdapter->get($key, $callback, $beta, $metadata);
        }, $key);
    }
}
