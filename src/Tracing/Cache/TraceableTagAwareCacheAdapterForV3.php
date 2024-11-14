<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\Cache;

use Sentry\State\HubInterface;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\Cache\PruneableInterface;
use Symfony\Component\Cache\ResettableInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * This implementation of a cache adapter aware of cache tags supports the
 * distributed tracing feature of Sentry.
 *
 * @internal
 */
final class TraceableTagAwareCacheAdapterForV3 implements TagAwareAdapterInterface, TagAwareCacheInterface, PruneableInterface, ResettableInterface
{
    /**
     * @phpstan-use TraceableCacheAdapterTrait<TagAwareAdapterInterface>
     */
    use TraceableCacheAdapterTrait;

    /**
     * @param HubInterface             $hub              The current hub
     * @param TagAwareAdapterInterface $decoratedAdapter The decorated cache adapter
     */
    public function __construct(HubInterface $hub, TagAwareAdapterInterface $decoratedAdapter)
    {
        $this->hub = $hub;
        $this->decoratedAdapter = $decoratedAdapter;
    }

    /**
     * {@inheritdoc}
     *
     * @param mixed[] $metadata
     */
    public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
    {
        return $this->traceFunction('cache.get_item', function () use ($key, $callback, $beta, &$metadata) {
            if (!$this->decoratedAdapter instanceof CacheInterface) {
                throw new \BadMethodCallException(sprintf('The %s::get() method is not supported because the decorated adapter does not implement the "%s" interface.', self::class, CacheInterface::class));
            }

            return $this->decoratedAdapter->get($key, $callback, $beta, $metadata);
        }, $key);
    }

    /**
     * {@inheritdoc}
     */
    public function invalidateTags(array $tags): bool
    {
        return $this->traceFunction('cache.invalidate_tags', function () use ($tags): bool {
            return $this->decoratedAdapter->invalidateTags($tags);
        });
    }
}
