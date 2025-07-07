<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Tracing\Cache;

use Sentry\SentryBundle\Tracing\Cache\TraceableCacheAdapter;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Contracts\Cache\NamespacedPoolInterface;

/**
 * @phpstan-extends AbstractTraceableCacheAdapterTest<TraceableCacheAdapter, AdapterInterface>
 */
final class TraceableCacheAdapterTest extends AbstractTraceableCacheAdapterTest
{
    /**
     * {@inheritdoc}
     */
    protected function createCacheAdapter(AdapterInterface $decoratedAdapter): TraceableCacheAdapter
    {
        return new TraceableCacheAdapter($this->hub, $decoratedAdapter);
    }

    /**
     * {@inheritdoc}
     */
    protected static function getAdapterClassFqcn(): string
    {
        return AdapterInterface::class;
    }

    public function testNamespacePoolImplementation(): void
    {
        if (!interface_exists(NamespacedPoolInterface::class)) {
            $this->markTestSkipped('NamespacedPoolInterface does not exists.');
        }

        $decoratedAdapter = $this->createMock(static::getAdapterClassFqcn());
        $adapter = $this->createCacheAdapter($decoratedAdapter);

        static::assertInstanceOf(NamespacedPoolInterface::class, $adapter);
    }
}
