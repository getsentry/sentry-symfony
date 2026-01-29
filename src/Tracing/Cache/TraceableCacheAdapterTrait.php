<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\Cache;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\InvalidArgumentException;
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
     * @var string|null
     */
    protected $namespace;

    /**
     * {@inheritdoc}
     */
    public function getItem($key): CacheItem
    {
        return $this->traceFunction('cache.get', function () use ($key): CacheItem {
            return $this->decoratedAdapter->getItem($key);
        }, $key);
    }

    /**
     * {@inheritdoc}
     *
     * @phpstan-return iterable<string, CacheItem>
     *
     * @psalm-return iterable<string, CacheItem>
     */
    public function getItems(array $keys = []): iterable
    {
        /** @psalm-return iterable<string, CacheItem> */
        return $this->traceFunction('cache.get', function () use ($keys): iterable {
            return $this->decoratedAdapter->getItems($keys);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function clear(string $prefix = ''): bool
    {
        return $this->traceFunction('cache.flush', function () use ($prefix): bool {
            return $this->decoratedAdapter->clear($prefix);
        }, $prefix);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $key): bool
    {
        return $this->traceFunction('cache.remove', function () use ($key): bool {
            if (!$this->decoratedAdapter instanceof CacheInterface) {
                throw new \BadMethodCallException(\sprintf('The %s::delete() method is not supported because the decorated adapter does not implement the "%s" interface.', self::class, CacheInterface::class));
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
        return $this->traceFunction('cache.remove', function () use ($key): bool {
            return $this->decoratedAdapter->deleteItem($key);
        }, $key);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteItems(array $keys): bool
    {
        return $this->traceFunction('cache.remove', function () use ($keys): bool {
            return $this->decoratedAdapter->deleteItems($keys);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function save(CacheItemInterface $item): bool
    {
        return $this->traceFunction('cache.put', function () use ($item): bool {
            return $this->decoratedAdapter->save($item);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->traceFunction('cache.put', function () use ($item): bool {
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
     * Traces a symfony operation and creating one span in the process.
     *
     * If you want to trace a get operation with callback, use {@see self::traceGet()} instead.
     *
     * @phpstan-template TResult
     *
     * @phpstan-param \Closure(): TResult $callback
     *
     * @phpstan-return TResult
     */
    private function traceFunction(string $spanOperation, \Closure $callback, ?string $spanDescription = null)
    {
        $span = $this->hub->getSpan();

        // Exit early if we have no span.
        if (null === $span) {
            return $callback();
        }

        $spanContext = SpanContext::make()
            ->setOp($spanOperation)
            ->setOrigin('auto.cache');

        if (null !== $spanDescription) {
            $spanContext->setDescription(urldecode($spanDescription));
        }

        $span = $span->startChild($spanContext);

        try {
            $result = $callback();

            $data = [];

            if ($result instanceof CacheItemInterface) {
                $data['cache.hit'] = $result->isHit();
                if ($result->isHit()) {
                    $data['cache.item_size'] = static::getCacheItemSize($result->get());
                }
            }

            $namespace = $this->getCacheNamespace();
            if (null !== $namespace) {
                $data['cache.namespace'] = $namespace;
            }

            if ([] !== $data) {
                $span->setData($data);
            }

            return $result;
        } finally {
            $span->finish();
        }
    }

    /**
     * Traces a Symfony Cache get() call with a get and optional put span.
     *
     * Produces 2 spans in case of a cache miss:
     * 1. 'cache.get' span
     * 2. 'cache.put' span
     *
     * If the callback uses code with sentry traces, those traces will be available in the trace explorer.
     *
     * Use this method if you want to instrument {@see CacheInterface::get()}.
     *
     * @param string                       $key
     * @param callable                     $callback
     * @param float|null                   $beta
     * @param array<int|string,mixed>|null $metadata
     *
     * @return mixed
     *
     * @throws InvalidArgumentException
     */
    private function traceGet(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null)
    {
        if (!$this->decoratedAdapter instanceof CacheInterface) {
            throw new \BadMethodCallException(\sprintf('The %s::get() method is not supported because the decorated adapter does not implement the "%s" interface.', self::class, CacheInterface::class));
        }
        $parentSpan = $this->hub->getSpan();

        // If we don't have a parent span we can just forward it.
        if (null === $parentSpan) {
            return $this->decoratedAdapter->get($key, $callback, $beta, $metadata);
        }

        $spanContext = SpanContext::make()
            ->setOp('cache.get')
            ->setOrigin('auto.cache');

        $spanContext->setDescription(urldecode($key));

        $getSpan = $parentSpan->startChild($spanContext);

        try {
            $this->hub->setSpan($getSpan);

            $wasMiss = false;
            $saveStartTimestamp = null;

            try {
                $value = $this->decoratedAdapter->get($key, static function (CacheItemInterface $item, &$save) use ($callback, &$wasMiss, &$saveStartTimestamp) {
                    $wasMiss = true;

                    $result = $callback($item, $save);

                    if ($save) {
                        $saveStartTimestamp = microtime(true);
                    }

                    return $result;
                }, $beta, $metadata);
            } catch (\Throwable $t) {
                $getSpan->finish();
                throw $t;
            }

            $now = microtime(true);

            $getData = [
                'cache.hit' => !$wasMiss,
                'cache.item_size' => self::getCacheItemSize($value),
            ];
            $namespace = $this->getCacheNamespace();
            if (null !== $namespace) {
                $getData['cache.namespace'] = $namespace;
            }
            $getSpan->setData($getData);

            // If we got a timestamp here we know that we missed
            if (null !== $saveStartTimestamp) {
                $getSpan->finish($saveStartTimestamp);
                $saveContext = SpanContext::make()
                    ->setOp('cache.put')
                    ->setOrigin('auto.cache')
                    ->setDescription(urldecode($key));
                $saveSpan = $parentSpan->startChild($saveContext);
                $saveSpan->setStartTimestamp($saveStartTimestamp);
                $saveData = [
                    'cache.item_size' => self::getCacheItemSize($value),
                ];
                if (null !== $namespace) {
                    $saveData['cache.namespace'] = $namespace;
                }
                $saveSpan->setData($saveData);
                $saveSpan->finish($now);
            } else {
                $getSpan->finish();
            }

            return $value;
        } finally {
            // We always want to restore the previous parent span.
            $this->hub->setSpan($parentSpan);
        }
    }

    /**
     * Calculates the size of the cached item.
     *
     * @param mixed $value
     *
     * @return int|null
     */
    public static function getCacheItemSize($value): ?int
    {
        // We only gather the payload size for strings since this is easy to figure out
        // and has basically no overhead.
        // Getting the size of objects would be more complex, and it would potentially
        // introduce more overhead since we don't get the size from the current framework abstraction.
        if (\is_string($value)) {
            return \strlen($value);
        }

        return null;
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

    /**
     * @return string|null
     */
    protected function getCacheNamespace(): ?string
    {
        return $this->namespace;
    }
}
