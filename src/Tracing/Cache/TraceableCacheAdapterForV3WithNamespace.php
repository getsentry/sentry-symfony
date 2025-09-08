<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\Cache;

use Sentry\State\HubInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\PruneableInterface;
use Symfony\Component\Cache\ResettableInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\NamespacedPoolInterface;

/**
 * This implementation of a cache adapter supports the distributed tracing
 * feature of Sentry.
 *
 * @internal
 */
final class TraceableCacheAdapterForV3WithNamespace implements AdapterInterface, NamespacedPoolInterface, CacheInterface, PruneableInterface, ResettableInterface
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
     * @param string  $key
     * @param mixed[] $metadata
     *
     * @return mixed
     */
    public function get($key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
    {
        return $this->traceGet($key, $callback, $beta, $metadata);
    }

    public function withSubNamespace(string $namespace): static
    {
        if (!$this->decoratedAdapter instanceof NamespacedPoolInterface) {
            throw new \BadMethodCallException(\sprintf('The %s::withSubNamespace() method is not supported because the decorated adapter does not implement the "%s" interface.', self::class, NamespacedPoolInterface::class));
        }

        $clone = clone $this;
        $clone->decoratedAdapter = $this->decoratedAdapter->withSubNamespace($namespace);

        return $clone;
    }
}
