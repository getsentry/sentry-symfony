<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\DependencyInjection\Compiler\CacheTracingPass;
use Sentry\SentryBundle\Tracing\Cache\TraceableCacheAdapter;
use Sentry\SentryBundle\Tracing\Cache\TraceableTagAwareCacheAdapter;
use Sentry\State\HubInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class CacheTracingPassTest extends TestCase
{
    public function testProcess(): void
    {
        $cacheAdapter = $this->createMock(AdapterInterface::class);
        $tagAwareCacheAdapter = $this->createMock(TagAwareAdapterInterface::class);
        $container = $this->createContainerBuilder(true);

        $container->register('app.cache.foo', \get_class($tagAwareCacheAdapter))
            ->setPublic(true)
            ->addTag('cache.pool');

        $container->register('app.cache.bar', \get_class($cacheAdapter))
            ->setPublic(true)
            ->addTag('cache.pool');

        $container->register('app.cache.baz')
            ->setPublic(true)
            ->setAbstract(true)
            ->addTag('cache.pool');

        $container->compile();

        $cacheTraceableDefinition = $container->findDefinition('app.cache.foo');

        $this->assertSame(TraceableTagAwareCacheAdapter::class, $cacheTraceableDefinition->getClass());
        $this->assertInstanceOf(Definition::class, $cacheTraceableDefinition->getArgument(1));
        $this->assertSame(\get_class($tagAwareCacheAdapter), $cacheTraceableDefinition->getArgument(1)->getClass());

        $cacheTraceableDefinition = $container->findDefinition('app.cache.bar');

        $this->assertSame(TraceableCacheAdapter::class, $cacheTraceableDefinition->getClass());
        $this->assertInstanceOf(Definition::class, $cacheTraceableDefinition->getArgument(1));
        $this->assertSame(\get_class($cacheAdapter), $cacheTraceableDefinition->getArgument(1)->getClass());

        $this->assertFalse($container->hasDefinition('app.cache.baz'));
    }

    public function testProcessDoesNothingIfConditionsForEnablingTracingAreMissing(): void
    {
        $container = $this->createContainerBuilder(false);
        $container->register('app.cache', AdapterInterface::class);
        $container->compile();

        $this->assertFalse($container->hasDefinition('app.cache.traceable.inner'));
    }

    private function createContainerBuilder(bool $isTracingActive): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->addCompilerPass(new CacheTracingPass());
        $container->setParameter('sentry.tracing.cache.enabled', $isTracingActive);

        $container->register(HubInterface::class, HubInterface::class);
        $container->register('sentry.tracing.traceable_cache_adapter', TraceableCacheAdapter::class)
            ->setAbstract(true)
            ->setArgument(0, new Reference(HubInterface::class))
            ->setArgument(1, null);

        $container->register('sentry.tracing.traceable_tag_aware_cache_adapter', TraceableTagAwareCacheAdapter::class)
            ->setAbstract(true)
            ->setArgument(0, new Reference(HubInterface::class))
            ->setArgument(1, null);

        return $container;
    }
}
