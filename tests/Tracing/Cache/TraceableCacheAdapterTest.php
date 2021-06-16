<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Tracing\Cache;

use Sentry\SentryBundle\Tracing\Cache\TraceableCacheAdapter;
use Symfony\Component\Cache\Adapter\AdapterInterface;

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
}
