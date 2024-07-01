<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\Cache;

use Psr\Cache\CacheItemInterface;
use Sentry\State\HubInterface;
use Sentry\Tracing\SpanContext;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\Cache\PruneableInterface;
use Symfony\Component\Cache\ResettableInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * @internal
 *
 * @phpstan-template T of AdapterInterface
 */
trait TraceableCacheAdapterTrait
{
    /**
     * @var HubInterface The current hub
     */
    private $hub;

    /**
     * @var AdapterInterface|TagAwareAdapterInterface The decorated adapter
     *
     * @phpstan-var T
     */
    private $decoratedAdapter;

    /**
     * {@inheritdoc}
     */
    public function getItem($key): CacheItem
    {
        return $this->traceFunction('cache.get_item', function () use ($key): CacheItem {
            return $this->decoratedAdapter->getItem($key);
        }, $key);
    }

    /**
     * {@inheritdoc}
     */
    public function getItems(array $keys = []): iterable
    {
        return $this->traceFunction('cache.get_items', function () use ($keys): iterable {
            return $this->decoratedAdapter->getItems($keys);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function clear(string $prefix = ''): bool
    {
        return $this->traceFunction('cache.clear', function () use ($prefix): bool {
            return $this->decoratedAdapter->clear($prefix);
        }, $prefix);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $key): bool
    {
        return $this->traceFunction('cache.delete_item', function () use ($key): bool {
            if (!$this->decoratedAdapter instanceof CacheInterface) {
                throw new \BadMethodCallException(sprintf('The %s::delete() method is not supported because the decorated adapter does not implement the "%s" interface.', self::class, CacheInterface::class));
            }

            return $this->decoratedAdapter->delete($key);
        }, $key);
    }

    /**
     * {@inheritdoc}
     */
    public function hasItem($key): bool
    {
        return $this->traceFunction('cache.has_item', function () use ($key): bool {
            return $this->decoratedAdapter->hasItem($key);
        }, $key);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteItem($key): bool
    {
        return $this->traceFunction('cache.delete_item', function () use ($key): bool {
            return $this->decoratedAdapter->deleteItem($key);
        }, $key);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteItems(array $keys): bool
    {
        return $this->traceFunction('cache.delete_items', function () use ($keys): bool {
            return $this->decoratedAdapter->deleteItems($keys);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function save(CacheItemInterface $item): bool
    {
        return $this->traceFunction('cache.save', function () use ($item): bool {
            return $this->decoratedAdapter->save($item);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->traceFunction('cache.save_deferred', function () use ($item): bool {
            return $this->decoratedAdapter->saveDeferred($item);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function commit(): bool
    {
        return $this->traceFunction('cache.commit', function (): bool {
            return $this->decoratedAdapter->commit();
        });
    }

    /**
     * {@inheritdoc}
     */
    public function prune(): bool
    {
        return $this->traceFunction('cache.prune', function (): bool {
            if (!$this->decoratedAdapter instanceof PruneableInterface) {
                return false;
            }

            return $this->decoratedAdapter->prune();
        });
    }

    /**
     * {@inheritdoc}
     */
    public function reset(): void
    {
        if ($this->decoratedAdapter instanceof ResettableInterface) {
            $this->decoratedAdapter->reset();
        }
    }

    /**
     * @phpstan-template TResult
     *
     * @phpstan-param \Closure(): TResult $callback
     *
     * @phpstan-return TResult
     */
    private function traceFunction(string $spanOperation, \Closure $callback, string $spanDescription = null)
    {
        $span = $this->hub->getSpan();

        if (null !== $span) {
            $spanContext = new SpanContext();
            $spanContext->setOp($spanOperation);
            if (null !== $spanDescription) {
                $spanContext->setDescription(urldecode($spanDescription));
            }

            $span = $span->startChild($spanContext);
        }

        try {
            return $callback();
        } finally {
            if (null !== $span) {
                $span->finish();
            }
        }
    }

    /**
     * @phpstan-param \Closure(CacheItem): CacheItem $callback
     * @phpstan-param string $key
     *
     * @phpstan-return callable(): CacheItem
     */
    private function setCallbackWrapper(callable $callback, string $key): callable
    {
        return function () use ($callback, $key): CacheItem {
            return $callback($this->decoratedAdapter->getItem($key));
        };
    }
}
