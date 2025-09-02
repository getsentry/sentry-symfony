<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\Cache;

use Sentry\State\HubInterface;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\Cache\PruneableInterface;
use Symfony\Component\Cache\ResettableInterface;
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
     * @param HubInterface $hub The current hub
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
    public function get($key, callable $callback, ?float $beta = null, ?array &$metadata = null)
    {
        return $this->traceGet($key, $callback, $beta, $metadata);
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
