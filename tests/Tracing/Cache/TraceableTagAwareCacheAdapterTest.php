<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Tracing\Cache;

use Sentry\SentryBundle\Tracing\Cache\TraceableTagAwareCacheAdapter;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

/**
 * @phpstan-extends AbstractTraceableCacheAdapterTest<TraceableTagAwareCacheAdapter>
 */
final class TraceableTagAwareCacheAdapterTest extends AbstractTraceableCacheAdapterTest
{
    /**
     * {@inheritdoc}
     */
    protected function createCacheAdapter(AdapterInterface $decoratedAdapter): AdapterInterface
    {
        return new TraceableTagAwareCacheAdapter($this->hub, $decoratedAdapter);
    }

    /**
     * {@inheritdoc}
     */
    protected static function getAdapterClassFqcn(): string
    {
        return TagAwareAdapterInterface::class;
    }
}
